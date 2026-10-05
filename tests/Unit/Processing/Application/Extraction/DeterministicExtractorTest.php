<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Extraction;

use App\Catalog\Domain\Entity\Provider;
use App\Catalog\Domain\Entity\ProviderIdentity;
use App\Catalog\Domain\Enum\ProviderIdentityType;
use App\Catalog\Domain\Repository\ProviderIdentityRepositoryInterface;
use App\Documents\Domain\Enum\DocumentType;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Processing\Application\Extraction\AmountParser;
use App\Processing\Application\Extraction\DateParser;
use App\Processing\Application\Extraction\DeterministicExtractor;
use App\Shared\Application\Clock;
use App\Shared\Domain\ValueObject\BillingPeriod;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Nivel 3 del pipeline: no se usa IA para extraer algo que se puede obtener de
 * forma fiable por código. La mayoría de facturas de proveedores de software son
 * plantillas repetidas con los mismos campos.
 */
#[CoversClass(DeterministicExtractor::class)]
final class DeterministicExtractorTest extends TestCase
{
    private ProviderIdentityRepositoryInterface&MockObject $identities;

    protected function setUp(): void
    {
        $this->identities = $this->createMock(ProviderIdentityRepositoryInterface::class);
    }

    private function extractor(): DeterministicExtractor
    {
        return new DeterministicExtractor(
            new AmountParser(),
            new DateParser(),
            $this->identities,
            new Clock(new MockClock(new DateTimeImmutable('2026-10-05 10:00:00'))),
        );
    }

    private function header(string $subject = '', ?string $from = null, ?string $fromName = null): ImapMessageHeader
    {
        return new ImapMessageHeader(
            uid: 1,
            messageId: '<abc@example.com>',
            fromAddress: $from,
            fromName: $fromName,
            replyTo: null,
            toAddresses: ['yo@example.com'],
            subject: $subject,
            receivedAt: new DateTimeImmutable('2026-10-05 10:00:00'),
            sizeBytes: 1024,
            contentType: 'text/plain',
            attachmentNames: [],
            attachmentTypes: [],
        );
    }

    private function knownProvider(string $name, string $domain): void
    {
        $identity = new ProviderIdentity(new Provider($name, mb_strtolower($name)), ProviderIdentityType::DOMAIN, $domain);

        $this->identities
            ->method('findByTypeAndValue')
            ->willReturnCallback(
                static fn (ProviderIdentityType $type, string $value): ?ProviderIdentity => ProviderIdentityType::DOMAIN === $type && $value === $domain ? $identity : null,
            );
    }

    public function testExtractsTheFullInvoiceOfAKnownProvider(): void
    {
        $this->knownProvider('OVH', 'ovh.com');

        $document = $this->extractor()->extract(
            $this->header('Factura OVH 2026-10', 'facturacion@ovh.com'),
            "Factura nº: FRA-2026-10-0042\nFecha: 2026-10-03\nTotal: 29,90 €\nFacturación mensual.",
        );

        self::assertSame(ExtractionTier::DETERMINISTIC, $document->tier);
        self::assertSame(2990, $document->amountMinor);
        self::assertSame('EUR', $document->currency);
        self::assertSame('FRA-2026-10-0042', $document->invoiceNumber);
        self::assertSame('2026-10-03', $document->invoiceDate?->format('Y-m-d'));
        self::assertSame('OVH', $document->providerName);
        self::assertSame('ovh.com', $document->senderDomain);
        self::assertSame(DocumentType::INVOICE, $document->documentType);
        self::assertTrue($document->isActionable());
    }

    /**
     * Un aviso de renovación gana a la mención de "factura": describe un cobro
     * futuro, que es la señal más accionable de las dos.
     */
    public function testRenewalNoticeWinsOverAnInvoiceMention(): void
    {
        $this->knownProvider('OVH', 'ovh.com');

        $document = $this->extractor()->extract(
            $this->header('Factura OVH 2026-10', 'facturacion@ovh.com'),
            "Factura nº: FRA-2026-10-0042\nTotal: 29,90 €\nSe renovará el 2026-11-03.",
        );

        self::assertSame(DocumentType::OTHER, $document->documentType);
        self::assertSame('2026-11-03', $document->renewalDate?->format('Y-m-d'));
    }

    public function testDetectsMonthlyPeriodicityFromTheBody(): void
    {
        $document = $this->extractor()->extract(
            $this->header('Suscripción'),
            'Se cobrará cada 1 mes. Total 9,99 €.',
        );

        self::assertSame(BillingPeriod::MONTHLY, $document->billingPeriod);
    }

    public function testDetectsAnnualPeriodicityFromALabel(): void
    {
        $document = $this->extractor()->extract(
            $this->header('Suscripción'),
            'Plan anual. Total 99,00 €.',
        );

        self::assertSame(BillingPeriod::ANNUAL, $document->billingPeriod);
    }

    public function testDetectsQuarterlyPeriodicity(): void
    {
        $document = $this->extractor()->extract(
            $this->header('Suscripción'),
            'Facturación trimestral. Total 30,00 €.',
        );

        self::assertSame(BillingPeriod::QUARTERLY, $document->billingPeriod);
    }

    public function testClassifiesAPriceChangeBeforeAnInvoice(): void
    {
        $document = $this->extractor()->extract(
            $this->header('Cambio de precio'),
            'Te informamos del nuevo precio de tu factura: 34,90 €.',
        );

        // Un cambio de precio no es una factura: el tipo de documento se queda
        // en `OTHER` aunque el cuerpo mencione la palabra "factura".
        self::assertSame(DocumentType::OTHER, $document->documentType);
        self::assertSame(3490, $document->amountMinor);
    }

    public function testClassifiesARenewalNotice(): void
    {
        $document = $this->extractor()->extract(
            $this->header('Aviso'),
            'Tu suscripción se renovará automáticamente el 2026-11-03 por 29,90 €.',
        );

        self::assertSame('2026-11-03', $document->renewalDate?->format('Y-m-d'));
    }

    /**
     * Un importe sin periodicidad no permite calcular un coste recurrente, así
     * que la confianza se queda por debajo del umbral y el mensaje va a revisión
     * en lugar de generar una propuesta inventada.
     */
    public function testIsNotActionableWithoutPeriodicity(): void
    {
        $this->knownProvider('OVH', 'ovh.com');

        $document = $this->extractor()->extract(
            $this->header('Factura OVH', 'facturacion@ovh.com'),
            'Total: 29,90 €',
        );

        self::assertNull($document->billingPeriod);
        self::assertFalse($document->isActionable());
        self::assertLessThanOrEqual(0.7, $document->confidence);
    }

    public function testIsNotActionableWithoutProvider(): void
    {
        $document = $this->extractor()->extract(
            $this->header('Factura'),
            'Total: 29,90 €. Facturación mensual.',
        );

        self::assertNull($document->providerName);
        self::assertFalse($document->isActionable());
    }

    public function testConfidenceGrowsWithTheFieldsItCanFill(): void
    {
        $this->knownProvider('OVH', 'ovh.com');

        $poor = $this->extractor()->extract($this->header('Aviso', 'facturacion@ovh.com'), 'Hola.');
        $rich = $this->extractor()->extract(
            $this->header('Factura OVH', 'facturacion@ovh.com'),
            'Factura nº: FRA-1. Total 29,90 €. Facturación mensual.',
        );

        self::assertGreaterThan($poor->confidence, $rich->confidence);
        self::assertSame(1.0, $rich->confidence);
    }

    public function testInvoiceNumberMustContainADigit(): void
    {
        $document = $this->extractor()->extract(
            $this->header('Factura'),
            'Factura numero: pendiente',
        );

        self::assertNull($document->invoiceNumber);
    }

    public function testTheInvoiceNumberDoesNotKeepTheSentencePunctuation(): void
    {
        $document = $this->extractor()->extract(
            $this->header('Factura'),
            'Factura nº: FRA-2026-10-0042. Total 29,90 €.',
        );

        self::assertSame('FRA-2026-10-0042', $document->invoiceNumber);
    }

    public function testRawSignalsRecordWhatWasFound(): void
    {
        $this->knownProvider('OVH', 'ovh.com');

        $document = $this->extractor()->extract(
            $this->header('Factura OVH', 'facturacion@ovh.com'),
            'Factura nº: FRA-1. Fecha: 2026-10-03. Total 29,90 €. Facturación mensual.',
        );

        self::assertArrayHasKey('amount', $document->rawSignals);
        self::assertArrayHasKey('invoiceNumber', $document->rawSignals);
        self::assertArrayHasKey('billingPeriod', $document->rawSignals);
        self::assertArrayHasKey('dates', $document->rawSignals);
    }

    public function testToArrayIsJsonSerialisable(): void
    {
        $this->knownProvider('OVH', 'ovh.com');

        $document = $this->extractor()->extract(
            $this->header('Factura OVH', 'facturacion@ovh.com'),
            'Factura nº: FRA-1. Total 29,90 €. Facturación mensual.',
        );

        $encoded = json_encode($document->toArray());

        self::assertIsString($encoded);
        self::assertSame('OVH', json_decode($encoded, true)['providerName']);
    }

    public function testNameIsStableForTheTransitionLog(): void
    {
        self::assertSame('deterministic', $this->extractor()->name());
    }

    public function testEmptyBodyStillProducesADocument(): void
    {
        $document = $this->extractor()->extract($this->header('Aviso'));

        self::assertNull($document->amountMinor);
        self::assertSame(0.0, $document->confidence);
        self::assertFalse($document->isActionable());
    }

    /**
     * La primera factura de cualquier proveedor llega, por definición, de un
     * remitente desconocido. Si el extractor exigiera un proveedor verificado,
     * el producto no podría descubrir nada nuevo nunca.
     */
    public function testAnUnknownSenderStillYieldsAProvisionalProviderName(): void
    {
        $document = $this->extractor()->extract(
            $this->header('Tu factura de octubre', 'facturacion@nuevoproveedor.com', 'Nuevo Proveedor S.L.'),
            'Factura nº: NP-2026-10. Total 19,99 €. Facturación mensual.',
        );

        self::assertSame('Nuevo Proveedor S.L.', $document->providerName);
        self::assertSame('Nuevo Proveedor S.L.', $document->serviceName);
        self::assertSame('no', $document->rawSignals['providerKnown']);
        self::assertTrue($document->isActionable(), 'Un proveedor desconocido no puede bloquear el descubrimiento.');
    }

    public function testTheProvisionalNameFallsBackToTheSenderDomain(): void
    {
        $document = $this->extractor()->extract(
            $this->header('Tu factura de octubre', 'facturacion@nuevoproveedor.com'),
            'Factura nº: NP-2026-10. Total 19,99 €. Facturación mensual.',
        );

        self::assertSame('nuevoproveedor.com', $document->providerName);
        self::assertSame('no', $document->rawSignals['providerKnown']);
    }

    public function testAProvisionalNameDoesNotInflateConfidence(): void
    {
        $body = 'Factura nº: NP-2026-10. Total 19,99 €. Facturación mensual.';

        $unknown = $this->extractor()->extract(
            $this->header('Tu factura de octubre', 'facturacion@nuevoproveedor.com', 'Nuevo Proveedor S.L.'),
            $body,
        );

        $this->knownProvider('OVH', 'ovh.com');

        $known = $this->extractor()->extract(
            $this->header('Tu factura de octubre', 'facturacion@ovh.com', 'OVH'),
            $body,
        );

        self::assertSame('yes', $known->rawSignals['providerKnown']);
        self::assertGreaterThan($unknown->confidence, $known->confidence, 'Conocer al proveedor debe subir la confianza, no bajarla.');
    }

    public function testAProvisionalNameIsTruncatedToKeepTheColumnSafe(): void
    {
        $document = $this->extractor()->extract(
            $this->header('Tu factura', 'facturacion@nuevoproveedor.com', str_repeat('A', 300)),
            'Total 19,99 €. Facturación mensual.',
        );

        self::assertNotNull($document->providerName);
        self::assertSame(120, mb_strlen($document->providerName));
    }
}
