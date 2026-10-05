<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Domain\Dto;

use App\Documents\Domain\Enum\DocumentType;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Processing\Domain\Dto\ExtractedDocument;
use App\Processing\Domain\Provider\ProviderParseResult;
use App\Shared\Domain\ValueObject\BillingPeriod;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `toArray()` es la forma en la que el resultado de la extracción se persiste
 * (en `Discovery.proposedData` y en la caché de extracción). Si `fromArray()`
 * no reconstruye exactamente lo mismo, la caché devolvería datos distintos a
 * los que produjo el extractor y el usuario vería propuestas incoherentes.
 */
#[CoversClass(ExtractedDocument::class)]
final class ExtractedDocumentTest extends TestCase
{
    private function full(): ExtractedDocument
    {
        return new ExtractedDocument(
            tier: ExtractionTier::KNOWN_PARSER,
            confidence: 0.93,
            amountMinor: 2990,
            currency: 'EUR',
            invoiceNumber: 'OVH-2026-10-0001',
            invoiceDate: new DateTimeImmutable('2026-10-03'),
            dueDate: new DateTimeImmutable('2026-10-17'),
            billingPeriod: BillingPeriod::MONTHLY,
            sender: 'facturas@ovh.com',
            senderDomain: 'ovh.com',
            subject: 'Tu factura de octubre',
            documentType: DocumentType::INVOICE,
            providerName: 'OVH',
            serviceName: 'VPS',
            plan: 'VPS Comfort',
            renewalDate: new DateTimeImmutable('2026-11-03'),
            rawSignals: ['amount' => '29,90 €', 'period' => 'mensual'],
        );
    }

    public function testItSurvivesARoundTripThroughItsArrayForm(): void
    {
        $original = $this->full();
        $restored = ExtractedDocument::fromArray($original->toArray());

        self::assertSame($original->tier, $restored->tier);
        self::assertSame($original->confidence, $restored->confidence);
        self::assertSame($original->amountMinor, $restored->amountMinor);
        self::assertSame($original->currency, $restored->currency);
        self::assertSame($original->invoiceNumber, $restored->invoiceNumber);
        self::assertSame($original->billingPeriod, $restored->billingPeriod);
        self::assertSame($original->sender, $restored->sender);
        self::assertSame($original->senderDomain, $restored->senderDomain);
        self::assertSame($original->subject, $restored->subject);
        self::assertSame($original->documentType, $restored->documentType);
        self::assertSame($original->providerName, $restored->providerName);
        self::assertSame($original->serviceName, $restored->serviceName);
        self::assertSame($original->plan, $restored->plan);
        self::assertSame($original->rawSignals, $restored->rawSignals);
        self::assertSame(
            $original->invoiceDate?->format('Y-m-d'),
            $restored->invoiceDate?->format('Y-m-d'),
        );
        self::assertSame(
            $original->dueDate?->format('Y-m-d'),
            $restored->dueDate?->format('Y-m-d'),
        );
        self::assertSame(
            $original->renewalDate?->format('Y-m-d'),
            $restored->renewalDate?->format('Y-m-d'),
        );
    }

    public function testAnEmptyArrayDegradesToAnEmptyDocumentInsteadOfFailing(): void
    {
        $document = ExtractedDocument::fromArray([]);

        self::assertSame(ExtractionTier::DETERMINISTIC, $document->tier);
        self::assertSame(0.0, $document->confidence);
        self::assertNull($document->amountMinor);
        self::assertNull($document->currency);
        self::assertNull($document->billingPeriod);
        self::assertSame(DocumentType::OTHER, $document->documentType);
        self::assertSame([], $document->rawSignals);
        self::assertFalse($document->isActionable());
    }

    /**
     * Una caché corrupta debe costar una extracción, no un error: el pipeline
     * no puede caerse porque una fila tenga un tipo inesperado.
     */
    public function testGarbageValuesDegradeToNullInsteadOfThrowing(): void
    {
        $document = ExtractedDocument::fromArray([
            'tier' => 'no-existe',
            'confidence' => 'alta',
            'amountMinor' => 'mucho',
            'currency' => 42,
            'invoiceDate' => 'no es una fecha',
            'billingPeriod' => 'cada luna llena',
            'documentType' => 'inventado',
            'rawSignals' => 'no es un array',
        ]);

        self::assertSame(ExtractionTier::DETERMINISTIC, $document->tier);
        self::assertSame(0.0, $document->confidence);
        self::assertNull($document->amountMinor);
        self::assertNull($document->currency);
        self::assertNull($document->invoiceDate);
        self::assertNull($document->billingPeriod);
        self::assertSame(DocumentType::OTHER, $document->documentType);
        self::assertSame([], $document->rawSignals);
    }

    public function testNumericStringsAreAcceptedForAmountAndConfidence(): void
    {
        $document = ExtractedDocument::fromArray([
            'amountMinor' => '2990',
            'confidence' => '0.93',
        ]);

        self::assertSame(2990, $document->amountMinor);
        self::assertSame(0.93, $document->confidence);
    }

    /**
     * Un acierto de caché devuelve la extracción de **otro** correo con el mismo
     * cuerpo. El importe y las fechas son del contenido, pero el asunto y el
     * remitente son de este mensaje: conservar los del original mostraría al
     * usuario datos de un correo que no ha visto.
     */
    public function testWithSenderContextReplacesOnlyTheMessageSpecificFields(): void
    {
        $original = $this->full();

        $refreshed = $original->withSenderContext('reenvio@miempresa.com', 'miempresa.com', 'RV: Tu factura de octubre');

        self::assertSame('reenvio@miempresa.com', $refreshed->sender);
        self::assertSame('miempresa.com', $refreshed->senderDomain);
        self::assertSame('RV: Tu factura de octubre', $refreshed->subject);

        self::assertSame($original->amountMinor, $refreshed->amountMinor);
        self::assertSame($original->currency, $refreshed->currency);
        self::assertSame($original->invoiceNumber, $refreshed->invoiceNumber);
        self::assertSame($original->billingPeriod, $refreshed->billingPeriod);
        self::assertSame($original->providerName, $refreshed->providerName);
        self::assertSame($original->tier, $refreshed->tier);
        self::assertSame($original->confidence, $refreshed->confidence);
        self::assertSame($original->rawSignals, $refreshed->rawSignals);
    }

    public function testWithSenderContextAcceptsMissingValues(): void
    {
        $refreshed = $this->full()->withSenderContext(null, null, null);

        self::assertNull($refreshed->sender);
        self::assertNull($refreshed->senderDomain);
        self::assertNull($refreshed->subject);
        self::assertTrue($refreshed->isActionable());
    }

    public function testAProviderParseOverlaysItsFieldsOnTheDocument(): void
    {
        $document = new ExtractedDocument(
            tier: ExtractionTier::DETERMINISTIC,
            confidence: 0.5,
            amountMinor: 1990,
            currency: 'EUR',
            invoiceNumber: null,
            invoiceDate: null,
            dueDate: null,
            billingPeriod: null,
            sender: 'facturas@ovh.com',
            senderDomain: 'ovh.com',
            subject: 'Tu factura',
            documentType: DocumentType::INVOICE,
            providerName: 'OVHcloud',
            serviceName: null,
            plan: null,
            renewalDate: null,
            rawSignals: ['amount' => '19,90 €'],
        );

        $parsed = $document->withProviderParse($this->parseResult(), 'OVHcloud');

        self::assertSame(2990, $parsed->amountMinor, 'El parser corrige el importe que el extractor genérico leyó mal.');
        self::assertSame('FRA-2026-1042', $parsed->invoiceNumber);
        self::assertSame('2026-10-03', $parsed->invoiceDate?->format('Y-m-d'));
        self::assertSame('2026-10-17', $parsed->dueDate?->format('Y-m-d'));
        self::assertSame(BillingPeriod::MONTHLY, $parsed->billingPeriod);
        self::assertSame('VPS Comfort', $parsed->plan);
        self::assertSame('VPS', $parsed->serviceName);
        self::assertSame('OVHcloud', $parsed->providerName);
    }

    public function testAProviderParseMarksTheDocumentAsReadByAKnownParser(): void
    {
        $parsed = $this->full()->withProviderParse($this->parseResult(), 'OVHcloud');

        self::assertSame(ExtractionTier::KNOWN_PARSER, $parsed->tier);
    }

    public function testAProviderParseNeverLowersTheConfidence(): void
    {
        $confident = $this->full()->withProviderParse($this->parseResult(confidence: 0.2), 'OVHcloud');
        $unsure = $this->full()->withProviderParse($this->parseResult(confidence: 0.99), 'OVHcloud');

        self::assertSame(0.93, $confident->confidence, 'Un parser flojo no debe empeorar lo que ya se sabía.');
        self::assertSame(0.99, $unsure->confidence);
    }

    public function testAProviderParseKeepsTheFieldsItDidNotRead(): void
    {
        $parsed = $this->full()->withProviderParse($this->parseResult(), 'OVHcloud');

        self::assertSame('facturas@ovh.com', $parsed->sender);
        self::assertSame('ovh.com', $parsed->senderDomain);
        self::assertSame('Tu factura de octubre', $parsed->subject);
        self::assertSame(DocumentType::INVOICE, $parsed->documentType);
    }

    public function testAProviderParseRecordsWhichParserWasUsed(): void
    {
        $parsed = $this->full()->withProviderParse($this->parseResult(), 'OVHcloud');

        self::assertSame('ovh', $parsed->rawSignals['parser']);
        self::assertSame('FRA-2026-1042', $parsed->rawSignals['invoiceNumber']);
        self::assertSame('29,90 €', $parsed->rawSignals['amount'], 'Las señales del extractor genérico no se pierden.');
    }

    public function testAProviderParseWithNoDataLeavesTheDocumentUntouched(): void
    {
        $original = $this->full();

        $parsed = $original->withProviderParse(
            new ProviderParseResult(
                parserKey: 'ovh',
                confidence: 0.0,
                amountMinor: null,
                currency: null,
                invoiceNumber: null,
                invoiceDate: null,
                dueDate: null,
                billingPeriod: null,
                plan: null,
                serviceName: null,
                renewalDate: null,
                signals: [],
            ),
            'OVHcloud',
        );

        self::assertSame($original->amountMinor, $parsed->amountMinor);
        self::assertSame($original->invoiceNumber, $parsed->invoiceNumber);
        self::assertSame($original->billingPeriod, $parsed->billingPeriod);
        self::assertSame($original->plan, $parsed->plan);
        self::assertSame($original->serviceName, $parsed->serviceName);
        self::assertSame(ExtractionTier::KNOWN_PARSER, $parsed->tier);
    }

    private function parseResult(float $confidence = 0.95): ProviderParseResult
    {
        return new ProviderParseResult(
            parserKey: 'ovh',
            confidence: $confidence,
            amountMinor: 2990,
            currency: 'EUR',
            invoiceNumber: 'FRA-2026-1042',
            invoiceDate: new DateTimeImmutable('2026-10-03'),
            dueDate: new DateTimeImmutable('2026-10-17'),
            billingPeriod: BillingPeriod::MONTHLY,
            plan: 'VPS Comfort',
            serviceName: 'VPS',
            renewalDate: new DateTimeImmutable('2026-11-03'),
            signals: ['invoiceNumber' => 'FRA-2026-1042'],
        );
    }
}
