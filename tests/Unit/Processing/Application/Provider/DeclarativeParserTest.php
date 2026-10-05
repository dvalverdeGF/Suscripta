<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Provider;

use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Processing\Application\Extraction\AmountParser;
use App\Processing\Application\Extraction\DateParser;
use App\Processing\Application\Provider\DeclarativeParser;
use App\Processing\Domain\Provider\ProviderParserContext;
use App\Shared\Domain\ValueObject\BillingPeriod;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeclarativeParser::class)]
final class DeclarativeParserTest extends TestCase
{
    private const string AMOUNT = '/total\s*:?\s*([0-9][0-9.,]*\s*(?:€|EUR))/iu';
    private const string NUMBER = '/numero\s*:?\s*([A-Z0-9][A-Z0-9\-\/]{3,29})/iu';
    private const string DATE = '/fecha\s*:?\s*([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4})/iu';
    private const string DUE = '/vence\s*:?\s*([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4})/iu';
    private const string PLAN = '/plan\s*:?\s*([A-Za-z0-9][A-Za-z0-9 .\-_]{2,59})/iu';

    private DeclarativeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DeclarativeParser(new AmountParser(), new DateParser());
    }

    public function testItReadsEveryConfiguredField(): void
    {
        $result = $this->parser->parse($this->context(
            body: "Total: 29,90 EUR\nNumero: FRA-2026-0001\nFecha: 03/10/2026\nVence: 17/10/2026\nPlan: Pro",
            config: [
                'amount_pattern' => self::AMOUNT,
                'invoice_number_pattern' => self::NUMBER,
                'invoice_date_pattern' => self::DATE,
                'due_date_pattern' => self::DUE,
                'period' => 'monthly',
                'plan_pattern' => self::PLAN,
                'service_name' => 'Proveedor de prueba',
            ],
        ));

        self::assertNotNull($result);
        self::assertSame('declarative', $result->parserKey);
        self::assertSame(2990, $result->amountMinor);
        self::assertSame('EUR', $result->currency);
        self::assertSame('FRA-2026-0001', $result->invoiceNumber);
        self::assertSame('2026-10-03', $result->invoiceDate?->format('Y-m-d'));
        self::assertSame('2026-10-17', $result->dueDate?->format('Y-m-d'));
        self::assertSame(BillingPeriod::MONTHLY, $result->billingPeriod);
        self::assertSame('Pro', $result->plan);
        self::assertSame('Proveedor de prueba', $result->serviceName);
        self::assertTrue($result->hasData());
    }

    public function testItRefusesADocumentThatDoesNotMatchTheRequiredFragments(): void
    {
        // `required_any` es lo que impide que una expresión regular laxa lea
        // importes de un boletín.
        $result = $this->parser->parse($this->context(
            body: 'Total: 29,90 EUR',
            config: [
                'required_any' => ['factura', 'invoice'],
                'amount_pattern' => self::AMOUNT,
            ],
        ));

        self::assertNull($result);
    }

    public function testItAcceptsADocumentThatMatchesAnyRequiredFragment(): void
    {
        $result = $this->parser->parse($this->context(
            body: 'Invoice total: 29,90 EUR',
            config: [
                'required_any' => ['factura', 'invoice'],
                'amount_pattern' => self::AMOUNT,
            ],
        ));

        self::assertNotNull($result);
        self::assertSame(2990, $result->amountMinor);
    }

    public function testItAcceptsAnyDocumentWhenThereIsNoRequiredFragment(): void
    {
        $result = $this->parser->parse($this->context(
            body: 'Total: 29,90 EUR',
            config: ['amount_pattern' => self::AMOUNT],
        ));

        self::assertNotNull($result);
    }

    public function testItReturnsNullWhenItReadsNothing(): void
    {
        // Un resultado sin datos es peor que no devolver nada: haría que el
        // pipeline dejara de intentar el extractor genérico.
        $result = $this->parser->parse($this->context(
            body: 'Hola, ¿qué tal?',
            config: ['amount_pattern' => self::AMOUNT],
        ));

        self::assertNull($result);
    }

    public function testItReturnsNullWhenThePatternDoesNotMatch(): void
    {
        $result = $this->parser->parse($this->context(
            body: 'Total: pendiente de calcular',
            config: ['amount_pattern' => self::AMOUNT],
        ));

        self::assertNull($result);
    }

    public function testItUsesTheConfiguredCurrency(): void
    {
        $result = $this->parser->parse($this->context(
            body: 'Total: 29.90 USD',
            config: [
                'currency' => 'USD',
                'amount_pattern' => '/total\s*:?\s*([0-9][0-9.,]*\s*(?:USD|\$))/iu',
            ],
        ));

        self::assertNotNull($result);
        self::assertSame('USD', $result->currency);
    }

    public function testItFallsBackToEuroForAnUnknownCurrency(): void
    {
        $result = $this->parser->parse($this->context(
            body: 'Total: 29,90 EUR',
            config: [
                'currency' => 'XXX',
                'amount_pattern' => self::AMOUNT,
            ],
        ));

        self::assertNotNull($result);
        self::assertSame('EUR', $result->currency);
    }

    public function testItIgnoresAnUnknownPeriodLabel(): void
    {
        $result = $this->parser->parse($this->context(
            body: 'Total: 29,90 EUR',
            config: [
                'period' => 'cada luna llena',
                'amount_pattern' => self::AMOUNT,
            ],
        ));

        self::assertNotNull($result);
        self::assertNull($result->billingPeriod);
    }

    public function testItReadsTheRenewalDate(): void
    {
        $result = $this->parser->parse($this->context(
            body: "Total: 29,90 EUR\nSe renueva el 03/11/2026",
            config: [
                'amount_pattern' => self::AMOUNT,
                'renewal_date_pattern' => '/se\s*renueva\s*el\s*([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4})/iu',
            ],
        ));

        self::assertNotNull($result);
        self::assertSame('2026-11-03', $result->renewalDate?->format('Y-m-d'));
    }

    public function testItScoresConfidenceByWhatItActuallyRead(): void
    {
        $full = $this->parser->parse($this->context(
            body: "Total: 29,90 EUR\nNumero: FRA-1\nFecha: 03/10/2026",
            config: [
                'amount_pattern' => self::AMOUNT,
                'invoice_number_pattern' => self::NUMBER,
                'invoice_date_pattern' => self::DATE,
                'period' => 'monthly',
            ],
        ));

        $partial = $this->parser->parse($this->context(
            body: 'Total: 29,90 EUR',
            config: ['amount_pattern' => self::AMOUNT],
        ));

        self::assertNotNull($full);
        self::assertNotNull($partial);
        self::assertSame(1.0, $full->confidence);
        self::assertSame(0.4, $partial->confidence);
    }

    public function testItRecordsWhichRuleMatched(): void
    {
        $result = $this->parser->parse($this->context(
            body: 'Total: 29,90 EUR',
            config: [
                'amount_pattern' => self::AMOUNT,
                'period' => 'monthly',
            ],
        ));

        self::assertNotNull($result);
        self::assertArrayHasKey('amount_pattern', $result->signals);
        self::assertSame('monthly', $result->signals['period']);
    }

    public function testItIdentifiesItself(): void
    {
        self::assertSame('declarative', $this->parser->key());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function context(string $body, array $config): ProviderParserContext
    {
        return new ProviderParserContext(
            header: new ImapMessageHeader(
                uid: 1,
                messageId: '<x@y>',
                fromAddress: 'facturas@proveedor.com',
                fromName: 'Proveedor',
                replyTo: null,
                toAddresses: ['yo@miempresa.com'],
                subject: 'Aviso de pago',
                receivedAt: new DateTimeImmutable('2026-10-03 10:00:00'),
                sizeBytes: 1000,
                contentType: 'text/plain',
                attachmentNames: [],
                attachmentTypes: [],
            ),
            bodyText: $body,
            providerName: 'Proveedor',
            config: $config,
        );
    }
}
