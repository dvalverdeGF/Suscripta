<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Text;

use App\Processing\Application\Text\DocumentTextExtractorRegistry;
use App\Processing\Application\Text\ExtractDocumentText;
use App\Processing\Domain\Ocr\OcrEngineInterface;
use App\Processing\Domain\Text\DocumentTextExtractorInterface;
use App\Processing\Domain\Text\TextExtractionMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function mb_strlen;
use function str_repeat;

#[CoversClass(ExtractDocumentText::class)]
final class ExtractDocumentTextTest extends TestCase
{
    private const string LONG_TEXT = 'Factura OVH numero FRA-2026-0001 total 29,90 EUR mensual';

    public function testItUsesTheTextLayerWhenThereIsEnoughText(): void
    {
        $useCase = $this->useCase(text: self::LONG_TEXT);

        $result = $useCase('contenido', 'application/pdf', 'factura.pdf');

        self::assertSame(TextExtractionMethod::TEXT, $result->method);
        self::assertSame('pdf', $result->extractor);
        self::assertSame(self::LONG_TEXT, $result->text);
    }

    public function testItNeverPaysForOcrWhenTheTextLayerIsEnough(): void
    {
        $ocr = $this->ocr(available: true, text: 'texto del OCR');
        $useCase = $this->useCase(text: self::LONG_TEXT, ocr: $ocr);

        $useCase('contenido', 'application/pdf', 'factura.pdf');

        self::assertSame(0, $ocr->calls);
    }

    public function testItFallsBackToOcrWhenTheTextLayerIsEmpty(): void
    {
        $ocr = $this->ocr(available: true, text: 'Factura OVH numero FRA-2026-0001 total 29,90 EUR');
        $useCase = $this->useCase(text: '', ocr: $ocr);

        $result = $useCase('contenido', 'application/pdf', 'escaneo.pdf');

        self::assertSame(TextExtractionMethod::OCR, $result->method);
        self::assertSame('fake-ocr', $result->extractor);
        self::assertSame(1, $ocr->calls);
    }

    public function testItFallsBackToOcrWhenTheTextLayerIsOnlyNoise(): void
    {
        // Un PDF escaneado devuelve basura de la capa de metadatos. Sin umbral,
        // ese ruido impediría el OCR y el pipeline se quedaría con un texto
        // inservible creyendo que lo tenía todo.
        $ocr = $this->ocr(available: true, text: 'Factura OVH numero FRA-2026-0001 total 29,90 EUR');
        $useCase = $this->useCase(text: 'PDF', ocr: $ocr);

        $result = $useCase('contenido', 'application/pdf', 'escaneo.pdf');

        self::assertSame(TextExtractionMethod::OCR, $result->method);
    }

    public function testItKeepsTheShortTextWhenThereIsNoOcr(): void
    {
        $useCase = $this->useCase(text: 'PDF', ocr: $this->ocr(available: false));

        $result = $useCase('contenido', 'application/pdf', 'escaneo.pdf');

        self::assertSame(TextExtractionMethod::TEXT, $result->method);
        self::assertSame('PDF', $result->text);
    }

    public function testItReturnsNothingWhenNeitherLayerProducesText(): void
    {
        $useCase = $this->useCase(text: '', ocr: $this->ocr(available: true, text: ''));

        $result = $useCase('contenido', 'application/pdf', 'escaneo.pdf');

        self::assertSame(TextExtractionMethod::NONE, $result->method);
        self::assertTrue($result->isEmpty());
    }

    public function testItReturnsNothingForEmptyContents(): void
    {
        $ocr = $this->ocr(available: true, text: 'algo');
        $useCase = $this->useCase(text: self::LONG_TEXT, ocr: $ocr);

        $result = $useCase("   \n  ", 'application/pdf', 'factura.pdf');

        self::assertSame(TextExtractionMethod::NONE, $result->method);
        self::assertSame(0, $ocr->calls);
    }

    public function testItDoesNotAskForOcrWhenTheEngineDoesNotClaimTheFormat(): void
    {
        $ocr = $this->ocr(available: true, text: 'algo', supports: false);
        $useCase = $this->useCase(text: '', ocr: $ocr);

        $result = $useCase('contenido', 'application/pdf', 'factura.pdf');

        self::assertSame(TextExtractionMethod::NONE, $result->method);
        self::assertSame(0, $ocr->calls);
    }

    public function testItTruncatesAnEnormousDocument(): void
    {
        $useCase = $this->useCase(text: str_repeat('a', 500), maxCharacters: 100);

        $result = $useCase('contenido', 'text/plain', 'notas.txt');

        self::assertTrue($result->truncated);
        self::assertSame(100, mb_strlen($result->text));
        self::assertSame(500, $result->characters);
    }

    public function testItDoesNotMarkAShortDocumentAsTruncated(): void
    {
        $useCase = $this->useCase(text: self::LONG_TEXT, maxCharacters: 100);

        $result = $useCase('contenido', 'text/plain', 'notas.txt');

        self::assertFalse($result->truncated);
        self::assertSame(mb_strlen(self::LONG_TEXT), $result->characters);
    }

    private function useCase(
        string $text,
        ?OcrEngineInterface $ocr = null,
        int $minCharacters = 40,
        int $maxCharacters = 200_000,
    ): ExtractDocumentText {
        return new ExtractDocumentText(
            new DocumentTextExtractorRegistry([$this->textExtractor($text)]),
            $ocr ?? $this->ocr(available: false),
            $minCharacters,
            $maxCharacters,
        );
    }

    private function textExtractor(string $text): DocumentTextExtractorInterface
    {
        return new class($text) implements DocumentTextExtractorInterface {
            public function __construct(private readonly string $text)
            {
            }

            public function name(): string
            {
                return 'pdf';
            }

            public function priority(): int
            {
                return 100;
            }

            public function supports(string $mimeType, string $filename): bool
            {
                return true;
            }

            public function extract(string $contents, string $mimeType, string $filename): ?string
            {
                return $this->text;
            }
        };
    }

    /**
     * @return OcrEngineInterface&object{calls: int}
     */
    private function ocr(bool $available, string $text = '', bool $supports = true): OcrEngineInterface
    {
        return new class($available, $text, $supports) implements OcrEngineInterface {
            public int $calls = 0;

            public function __construct(
                private readonly bool $available,
                private readonly string $text,
                private readonly bool $claims,
            ) {
            }

            public function name(): string
            {
                return 'fake-ocr';
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function supports(string $mimeType, string $filename): bool
            {
                return $this->claims;
            }

            public function extract(string $contents, string $mimeType, string $filename): ?string
            {
                ++$this->calls;

                return $this->text;
            }
        };
    }
}
