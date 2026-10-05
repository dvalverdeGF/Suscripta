<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Infrastructure\Text;

use App\Processing\Infrastructure\Text\PdfTextExtractor;
use App\Tests\Support\Processing\PdfFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PdfTextExtractor::class)]
final class PdfTextExtractorTest extends TestCase
{
    private PdfTextExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new PdfTextExtractor();
    }

    public function testItReadsTheTextLayerOfAGeneratedInvoice(): void
    {
        $text = $this->extractor->extract(
            PdfFixture::withText('Factura OVH numero FRA-1 total 29,90 EUR'),
            'application/pdf',
            'factura.pdf',
        );

        self::assertNotNull($text);
        self::assertStringContainsString('FRA-1', $text);
        self::assertStringContainsString('29,90', $text);
    }

    public function testAScannedPdfReturnsAnEmptyStringAndNotNull(): void
    {
        // La diferencia importa: `''` significa "es mi formato pero no tiene
        // texto", que es la señal para pasar a OCR. `null` significaría "no es
        // mi formato" y el pipeline no intentaría nada más.
        $text = $this->extractor->extract(PdfFixture::withoutText(), 'application/pdf', 'escaneo.pdf');

        self::assertSame('', $text);
    }

    public function testACorruptPdfDegradesInsteadOfThrowing(): void
    {
        $text = $this->extractor->extract('%PDF-1.4 basura sin xref', 'application/pdf', 'roto.pdf');

        self::assertSame('', $text);
    }

    public function testItClaimsPdfMimeTypes(): void
    {
        self::assertTrue($this->extractor->supports('application/pdf', 'x'));
        self::assertTrue($this->extractor->supports('application/x-pdf', 'x'));
        self::assertTrue($this->extractor->supports('APPLICATION/PDF', 'x'));
    }

    public function testItFallsBackToTheExtensionWhenTheProviderSendsOctetStream(): void
    {
        self::assertTrue($this->extractor->supports('application/octet-stream', 'factura.PDF'));
    }

    public function testItDoesNotClaimOtherFormats(): void
    {
        self::assertFalse($this->extractor->supports('text/plain', 'notas.txt'));
        self::assertFalse($this->extractor->supports('image/png', 'foto.png'));
    }

    public function testItReturnsNullForAFormatItDoesNotHandle(): void
    {
        self::assertNull($this->extractor->extract('hola', 'text/plain', 'notas.txt'));
    }

    public function testItRunsBeforeTheGenericExtractor(): void
    {
        self::assertGreaterThan(0, $this->extractor->priority());
    }

    public function testItIdentifiesItself(): void
    {
        self::assertSame('pdf', $this->extractor->name());
    }
}
