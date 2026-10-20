<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Provider\Parsers;

use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Processing\Application\Extraction\AmountParser;
use App\Processing\Application\Extraction\DateParser;
use App\Processing\Application\Extraction\InvoiceNumberReader;
use App\Processing\Application\Provider\Parsers\AcmeParser;
use App\Processing\Domain\Provider\ProviderParserContext;
use App\Shared\Domain\ValueObject\BillingPeriod;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AcmeParser::class)]
final class AcmeParserTest extends TestCase
{
    private AcmeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new AcmeParser(new AmountParser(), new DateParser(), new InvoiceNumberReader());
    }

    public function testItReadsAnAcmeInvoice(): void
    {
        $result = $this->parser->parse($this->context(<<<'TXT'
            Acme Cloud
            Factura n° ACME-2026-11-0002
            Fecha de factura: 03/11/2026
            Plan: Storage 100
            Total: 19,90 EUR
            Fecha de vencimiento: 17/11/2026
            TXT));

        self::assertNotNull($result);
        self::assertSame('acme', $result->parserKey);
        self::assertSame(1990, $result->amountMinor);
        self::assertSame('EUR', $result->currency);
        self::assertSame('ACME-2026-11-0002', $result->invoiceNumber);
        self::assertSame('2026-11-03', $result->invoiceDate?->format('Y-m-d'));
        self::assertSame('2026-11-17', $result->dueDate?->format('Y-m-d'));
        self::assertSame('Storage 100', $result->plan);
        self::assertSame('Acme Cloud', $result->serviceName);
        self::assertSame(BillingPeriod::MONTHLY, $result->billingPeriod);
    }

    public function testItReadsTheTotalLabelWithEuros(): void
    {
        $result = $this->parser->parse($this->context("Acme Cloud\nTotal: 12,00 €\n"));

        self::assertNotNull($result);
        self::assertSame(1200, $result->amountMinor);
    }

    public function testAnAnnualSubscriptionIsDetected(): void
    {
        $result = $this->parser->parse($this->context("Acme Cloud\nTotal: 99,00 €\nSuscripción anual\n"));

        self::assertNotNull($result);
        self::assertSame(BillingPeriod::ANNUAL, $result->billingPeriod);
    }

    public function testAQuarterlySubscriptionIsDetected(): void
    {
        $result = $this->parser->parse($this->context("Acme Cloud\nTotal: 99,00 €\nFacturación trimestral\n"));

        self::assertNotNull($result);
        self::assertSame(BillingPeriod::QUARTERLY, $result->billingPeriod);
    }

    public function testItAssumesMonthlyWhenTheDocumentDoesNotSayOtherwise(): void
    {
        $result = $this->parser->parse($this->context("Acme Cloud\nTotal: 9,99 €\n"));

        self::assertNotNull($result);
        self::assertSame(BillingPeriod::MONTHLY, $result->billingPeriod);
    }

    public function testItRefusesADocumentThatIsNotFromAcme(): void
    {
        $result = $this->parser->parse($this->context("OVHcloud\nTotal: 29,90 €\n", subject: 'Tu factura de OVHcloud'));

        self::assertNull($result);
    }

    public function testItRefusesAnAcmeDocumentWithoutAnyUsableData(): void
    {
        $result = $this->parser->parse($this->context("Acme Cloud\nGracias por confiar en nosotros.\n"));

        self::assertNull($result);
    }

    public function testTheConfidenceReflectsWhatWasRead(): void
    {
        $full = $this->parser->parse($this->context(<<<'TXT'
            Acme Cloud
            Factura n° ACME-1
            Fecha de factura: 03/11/2026
            Total: 19,90 EUR
            TXT));

        $amountOnly = $this->parser->parse($this->context("Acme Cloud\nTotal: 19,90 EUR\n"));

        self::assertNotNull($full);
        self::assertNotNull($amountOnly);
        self::assertSame(1.0, $full->confidence);
        self::assertSame(0.45, $amountOnly->confidence);
    }

    public function testItRecordsWhatItMatched(): void
    {
        $result = $this->parser->parse($this->context("Acme Cloud\nFactura n° ACME-1\nTotal: 19,90 EUR\n"));

        self::assertNotNull($result);
        self::assertSame('acme', $result->signals['format']);
        self::assertSame('ACME-1', $result->signals['invoiceNumber']);
        self::assertArrayHasKey('amount', $result->signals);
    }

    public function testTheKeyIsStable(): void
    {
        self::assertSame('acme', $this->parser->key());
    }

    private function context(string $body, string $subject = 'Tu factura de Acme Cloud'): ProviderParserContext
    {
        return new ProviderParserContext(
            header: new ImapMessageHeader(
                uid: 1,
                messageId: '<x@y>',
                fromAddress: 'facturacion@acme.example',
                fromName: 'Acme Cloud',
                replyTo: null,
                toAddresses: ['yo@miempresa.com'],
                subject: $subject,
                receivedAt: new DateTimeImmutable('2026-11-03 08:00:00'),
                sizeBytes: 2000,
                contentType: 'text/plain',
                attachmentNames: [],
                attachmentTypes: [],
            ),
            bodyText: $body,
            providerName: 'Acme Cloud',
        );
    }
}
