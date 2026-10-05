<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Billing;

use App\Catalog\Domain\Entity\Provider;
use App\Catalog\Domain\Entity\ProviderIdentity;
use App\Catalog\Domain\Enum\ProviderIdentityType;
use App\Catalog\Domain\Repository\ProviderIdentityRepositoryInterface;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Processing\Application\Billing\BillingScoreCalculator;
use App\Processing\Application\Billing\BillingScoreWeights;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * El `billingScore` es el guardián de coste del pipeline: lo que no pasa por
 * aquí no se descarga. Un falso positivo cuesta una descarga y un análisis; un
 * falso negativo pierde una factura para siempre.
 */
#[CoversClass(BillingScoreCalculator::class)]
final class BillingScoreCalculatorTest extends TestCase
{
    private ProviderIdentityRepositoryInterface&MockObject $identities;

    protected function setUp(): void
    {
        $this->identities = $this->createMock(ProviderIdentityRepositoryInterface::class);
    }

    private function calculator(?BillingScoreWeights $weights = null): BillingScoreCalculator
    {
        return new BillingScoreCalculator($weights ?? BillingScoreWeights::fromArray([]), $this->identities);
    }

    /**
     * @param list<string> $attachmentNames
     * @param list<string> $attachmentTypes
     */
    private function header(
        string $subject = '',
        ?string $from = null,
        array $attachmentNames = [],
        array $attachmentTypes = [],
        ?string $contentType = null,
    ): ImapMessageHeader {
        return new ImapMessageHeader(
            uid: 1,
            messageId: '<abc@example.com>',
            fromAddress: $from,
            fromName: null,
            replyTo: null,
            toAddresses: ['yo@example.com'],
            subject: $subject,
            receivedAt: new DateTimeImmutable('2026-10-05 10:00:00'),
            sizeBytes: 1024,
            contentType: $contentType,
            attachmentNames: $attachmentNames,
            attachmentTypes: $attachmentTypes,
        );
    }

    private function knownProvider(string $name, ProviderIdentityType $type, string $value): void
    {
        $identity = new ProviderIdentity(new Provider($name, mb_strtolower($name)), $type, $value);

        $this->identities
            ->method('findByTypeAndValue')
            ->willReturnCallback(
                static fn (ProviderIdentityType $asked, string $askedValue): ?ProviderIdentity => $asked === $type && $askedValue === mb_strtolower($value) ? $identity : null,
            );
    }

    public function testAnOrdinaryNewsletterIsNotACandidate(): void
    {
        $result = $this->calculator()->score($this->header(
            subject: 'Novedades de octubre',
            from: 'newsletter@tienda.com',
        ));

        self::assertFalse($result->isCandidate());
        self::assertLessThan(40, $result->score);
    }

    public function testAnInvoiceFromAKnownProviderIsACandidate(): void
    {
        $this->knownProvider('OVH', ProviderIdentityType::DOMAIN, 'ovh.com');

        $result = $this->calculator()->score($this->header(
            subject: 'Factura 2026-10',
            from: 'facturacion@ovh.com',
            attachmentNames: ['factura-2026-10.pdf'],
            attachmentTypes: ['application/pdf'],
        ));

        self::assertTrue($result->isCandidate());
        self::assertGreaterThanOrEqual(40, $result->score);
    }

    public function testEverySignalIsExplainable(): void
    {
        $this->knownProvider('OVH', ProviderIdentityType::DOMAIN, 'ovh.com');

        $result = $this->calculator()->score($this->header(
            subject: 'Factura 2026-10',
            from: 'facturacion@ovh.com',
            attachmentNames: ['factura-2026-10.pdf'],
            attachmentTypes: ['application/pdf'],
        ));

        $signals = array_column($result->reasonsAsArray(), 'signal');

        self::assertContains('subject_keyword', $signals);
        self::assertContains('known_provider', $signals);
        self::assertContains('pdf_attachment', $signals);
        self::assertContains('billing_local_part', $signals);
        self::assertContains('invoice_attachment_name', $signals);
        self::assertStringContainsString('Puntuación', $result->explain());
    }

    public function testScoreIsClampedToTheHundred(): void
    {
        $this->knownProvider('OVH', ProviderIdentityType::DOMAIN, 'ovh.com');

        $result = $this->calculator()->score($this->header(
            subject: 'Factura renovación pago recibo subscription',
            from: 'facturas@ovh.com',
            attachmentNames: ['factura.pdf'],
            attachmentTypes: ['application/pdf'],
        ), 'Total 29,90 €. Se renovará el 2026-11-03.');

        self::assertSame(100, $result->score);
    }

    public function testScoreNeverGoesBelowZero(): void
    {
        $weights = BillingScoreWeights::fromArray(['ignored_senders' => ['spam@example.com']]);

        $result = $this->calculator($weights)->score($this->header(
            subject: 'Re: consulta',
            from: 'spam@example.com',
        ));

        self::assertSame(0, $result->score);
    }

    public function testIgnoredSenderIsPenalised(): void
    {
        $weights = BillingScoreWeights::fromArray(['ignored_senders' => ['ruido@example.com']]);

        $result = $this->calculator($weights)->score($this->header(
            subject: 'Factura 2026-10',
            from: 'ruido@example.com',
        ));

        $signals = array_column($result->reasonsAsArray(), 'signal');

        self::assertContains('ignored_sender', $signals);
        self::assertFalse($result->isCandidate());
    }

    /**
     * Un boletín con la palabra "invoice" en el asunto no es una factura: por
     * eso el score combina señales independientes en lugar de buscar palabras.
     */
    public function testNewsletterLocalPartIsPenalised(): void
    {
        $result = $this->calculator()->score($this->header(
            subject: 'Your invoice is ready',
            from: 'newsletter@example.com',
        ));

        $signals = array_column($result->reasonsAsArray(), 'signal');

        self::assertContains('newsletter', $signals);
        self::assertFalse($result->isCandidate());
    }

    public function testReplyOnAnInvoiceThreadIsPenalised(): void
    {
        $result = $this->calculator()->score($this->header(
            subject: 'Re: Factura 2026-10',
            from: 'compras@example.com',
        ));

        $signals = array_column($result->reasonsAsArray(), 'signal');

        self::assertContains('own_thread', $signals);
    }

    public function testAmountInTheBodyCountsOnlyWhenTheBodyIsAvailable(): void
    {
        $header = $this->header(subject: 'Aviso de cuenta', from: 'avisos@example.com');

        $withoutBody = $this->calculator()->score($header);
        $withBody = $this->calculator()->score($header, 'El importe es 29,90 €');

        self::assertGreaterThan($withoutBody->score, $withBody->score);
    }

    public function testProviderIsResolvedByDomainBeforeSender(): void
    {
        $this->knownProvider('OVH', ProviderIdentityType::DOMAIN, 'ovh.com');

        $result = $this->calculator()->score($this->header(
            subject: 'Aviso',
            from: 'cualquiera@ovh.com',
        ));

        $detail = null;

        foreach ($result->reasonsAsArray() as $reason) {
            if ('known_provider' === $reason['signal']) {
                $detail = $reason['detail'] ?? null;
            }
        }

        self::assertSame('OVH', $detail);
    }

    public function testPdfIsDetectedByMimeTypeEvenWithoutExtension(): void
    {
        $result = $this->calculator()->score($this->header(
            subject: 'Aviso',
            from: 'avisos@example.com',
            attachmentNames: ['documento'],
            attachmentTypes: ['application/pdf'],
        ));

        self::assertContains('pdf_attachment', array_column($result->reasonsAsArray(), 'signal'));
    }

    public function testThresholdIsConfigurable(): void
    {
        $weights = BillingScoreWeights::fromArray(['threshold' => 90]);

        $result = $this->calculator($weights)->score($this->header(
            subject: 'Factura 2026-10',
            from: 'facturacion@example.com',
        ));

        self::assertSame(90, $result->threshold);
        self::assertFalse($result->isCandidate());
    }

    public function testWeightsAreConfigurable(): void
    {
        $weights = BillingScoreWeights::fromArray(['subject_keyword' => 100]);

        $result = $this->calculator($weights)->score($this->header(subject: 'Factura'));

        self::assertSame(100, $result->score);
    }

    public function testEmptyReasonsAreExplained(): void
    {
        $result = $this->calculator()->score($this->header(subject: 'Hola'));

        self::assertSame([], $result->reasons);
        self::assertStringContainsString('Sin señales', $result->explain());
    }
}
