<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Infrastructure\Text;

use App\Processing\Infrastructure\Text\PlainTextExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlainTextExtractor::class)]
final class PlainTextExtractorTest extends TestCase
{
    private PlainTextExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new PlainTextExtractor();
    }

    public function testItReturnsPlainTextAsIs(): void
    {
        $text = $this->extractor->extract("  Factura 29,90 EUR\n", 'text/plain', 'factura.txt');

        self::assertSame('Factura 29,90 EUR', $text);
    }

    public function testItStripsHtmlMarkup(): void
    {
        $html = '<html><head><style>p{color:red}</style></head><body>'
            .'<p>Importe total: 29,90&nbsp;&euro;</p><script>alert(1)</script>'
            .'<div>Factura FRA-1</div></body></html>';

        $text = $this->extractor->extract($html, 'text/html', 'factura.html');

        self::assertNotNull($text);
        self::assertStringContainsString('Importe total: 29,90', $text);
        self::assertStringContainsString('Factura FRA-1', $text);
        self::assertStringNotContainsString('<p>', $text);
        self::assertStringNotContainsString('alert(1)', $text);
        self::assertStringNotContainsString('color:red', $text);
    }

    public function testItConvertsLatin1ToUtf8(): void
    {
        // Bytes Latin-1 de verdad: `\xF3` es `ó` en ISO-8859-1 y no es UTF-8
        // válido, que es justo lo que debe detectar el extractor.
        $latin1 = "Facturaci\xF3n mensual";

        $text = $this->extractor->extract($latin1, 'text/plain', 'factura.txt');

        self::assertSame('Facturación mensual', $text);
    }

    public function testItClaimsTextMimeTypesAndExtensions(): void
    {
        self::assertTrue($this->extractor->supports('text/plain', 'x'));
        self::assertTrue($this->extractor->supports('text/csv', 'x'));
        self::assertTrue($this->extractor->supports('application/json', 'x'));
        self::assertTrue($this->extractor->supports('text/markdown', 'x'));
        self::assertTrue($this->extractor->supports('application/octet-stream', 'factura.csv'));
    }

    public function testItDoesNotClaimBinaryFormats(): void
    {
        self::assertFalse($this->extractor->supports('application/pdf', 'factura.pdf'));
        self::assertFalse($this->extractor->supports('image/png', 'foto.png'));
    }

    public function testItReturnsNullForAFormatItDoesNotHandle(): void
    {
        self::assertNull($this->extractor->extract('x', 'application/pdf', 'factura.pdf'));
    }

    public function testItRunsAfterTheSpecificExtractors(): void
    {
        self::assertSame(0, $this->extractor->priority());
    }

    public function testItIdentifiesItself(): void
    {
        self::assertSame('plain', $this->extractor->name());
    }
}
