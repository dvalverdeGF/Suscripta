<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Provider\Parsers;

use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Processing\Application\Extraction\AmountParser;
use App\Processing\Application\Extraction\DateParser;
use App\Processing\Application\Provider\Parsers\OvhParser;
use App\Processing\Domain\Provider\ProviderParserContext;
use App\Shared\Domain\ValueObject\BillingPeriod;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OvhParser::class)]
final class OvhParserTest extends TestCase
{
    private OvhParser $parser;

    protected function setUp(): void
    {
        $this->parser = new OvhParser(new AmountParser(), new DateParser());
    }

    public function testItReadsASpanishInvoice(): void
    {
        $result = $this->parser->parse($this->context(<<<'TXT'
            OVHcloud
            Factura n° FRA-2026-1042
            Fecha de factura: 03/10/2026
            Suscripción: VPS Comfort
            Importe total: 29,90 €
            Fecha de vencimiento: 17/10/2026
            TXT));

        self::assertNotNull($result);
        self::assertSame('ovh', $result->parserKey);
        self::assertSame(2990, $result->amountMinor);
        self::assertSame('EUR', $result->currency);
        self::assertSame('FRA-2026-1042', $result->invoiceNumber);
        self::assertSame('2026-10-03', $result->invoiceDate?->format('Y-m-d'));
        self::assertSame('2026-10-17', $result->dueDate?->format('Y-m-d'));
        self::assertSame('VPS Comfort', $result->plan);
        self::assertSame('OVHcloud', $result->serviceName);
        self::assertSame(BillingPeriod::MONTHLY, $result->billingPeriod);
    }

    public function testItReadsAFrenchInvoice(): void
    {
        $result = $this->parser->parse($this->context(<<<'TXT'
            OVH SAS
            Facture n° FR-998877
            Date de facture: 05.10.2026
            Abonnement: Hébergement Pro
            Montant total: 1 234,56 €
            Date d'échéance: 19.10.2026
            TXT));

        self::assertNotNull($result);
        self::assertSame(123456, $result->amountMinor);
        self::assertSame('FR-998877', $result->invoiceNumber);
        self::assertSame('2026-10-05', $result->invoiceDate?->format('Y-m-d'));
        self::assertSame('2026-10-19', $result->dueDate?->format('Y-m-d'));
    }

    public function testItReadsTheTotalTtcLabel(): void
    {
        $result = $this->parser->parse($this->context("OVH\nTotal TTC : 12,00 €\n"));

        self::assertNotNull($result);
        self::assertSame(1200, $result->amountMinor);
    }

    public function testAnAnnualSubscriptionIsDetected(): void
    {
        $result = $this->parser->parse($this->context("OVH\nImporte total: 99,00 €\nSuscripción anual\n"));

        self::assertNotNull($result);
        self::assertSame(BillingPeriod::ANNUAL, $result->billingPeriod);
    }

    public function testAQuarterlySubscriptionIsDetected(): void
    {
        $result = $this->parser->parse($this->context("OVH\nImporte total: 99,00 €\nFacturación trimestral\n"));

        self::assertNotNull($result);
        self::assertSame(BillingPeriod::QUARTERLY, $result->billingPeriod);
    }

    public function testItAssumesMonthlyWhenTheDocumentDoesNotSayOtherwise(): void
    {
        $result = $this->parser->parse($this->context("OVH\nImporte total: 9,99 €\n"));

        self::assertNotNull($result);
        self::assertSame(BillingPeriod::MONTHLY, $result->billingPeriod);
    }

    public function testItRefusesADocumentThatIsNotFromOvh(): void
    {
        $result = $this->parser->parse($this->context("Acme Cloud\nImporte total: 29,90 €\n", subject: 'Tu factura de Acme Cloud'));

        self::assertNull($result);
    }

    public function testItRefusesAnOvhDocumentWithoutAnyUsableData(): void
    {
        $result = $this->parser->parse($this->context("OVHcloud\nGracias por confiar en nosotros.\n"));

        self::assertNull($result);
    }

    public function testTheConfidenceReflectsWhatWasRead(): void
    {
        $full = $this->parser->parse($this->context(<<<'TXT'
            OVH
            Factura n° FRA-1
            Fecha de factura: 03/10/2026
            Importe total: 29,90 €
            TXT));

        $amountOnly = $this->parser->parse($this->context("OVH\nImporte total: 29,90 €\n"));

        self::assertNotNull($full);
        self::assertNotNull($amountOnly);
        self::assertSame(1.0, $full->confidence);
        self::assertSame(0.45, $amountOnly->confidence);
    }

    public function testItRecordsWhatItMatched(): void
    {
        $result = $this->parser->parse($this->context("OVH\nFactura n° FRA-1\nImporte total: 29,90 €\n"));

        self::assertNotNull($result);
        self::assertSame('ovh', $result->signals['format']);
        self::assertSame('FRA-1', $result->signals['invoiceNumber']);
        self::assertArrayHasKey('amount', $result->signals);
    }

    public function testTheKeyIsStable(): void
    {
        self::assertSame('ovh', $this->parser->key());
    }

    private function context(string $body, string $subject = 'Tu factura de OVHcloud'): ProviderParserContext
    {
        return new ProviderParserContext(
            header: new ImapMessageHeader(
                uid: 1,
                messageId: '<x@y>',
                fromAddress: 'factures@ovh.com',
                fromName: 'OVHcloud',
                replyTo: null,
                toAddresses: ['yo@miempresa.com'],
                subject: $subject,
                receivedAt: new DateTimeImmutable('2026-10-03 08:00:00'),
                sizeBytes: 2000,
                contentType: 'text/plain',
                attachmentNames: [],
                attachmentTypes: [],
            ),
            bodyText: $body,
            providerName: 'OVHcloud',
        );
    }
}
