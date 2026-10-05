<?php

declare(strict_types=1);

namespace App\Processing\Application\Text;

use App\Processing\Domain\Ocr\OcrEngineInterface;
use App\Processing\Domain\Text\DocumentText;
use App\Processing\Domain\Text\TextExtractionMethod;

use function mb_strlen;
use function mb_substr;
use function trim;

/**
 * Lee un documento: primero la capa de texto, y solo si no hay, OCR
 * (ARCHITECTURE.md §13.6, D-24).
 *
 * El orden no es un detalle de implementación, es la regla de coste del
 * producto: el OCR es lo más caro que hacemos en local, así que solo se paga
 * cuando el documento demuestra que no trae texto. Un PDF generado por un
 * proveedor nunca llega aquí.
 *
 * El umbral de caracteres existe porque un PDF escaneado **sí** devuelve algo:
 * basura de la capa de metadatos, o dos o tres letras del encabezado. Sin
 * umbral, ese ruido impediría el OCR y el pipeline se quedaría con un texto
 * inservible creyendo que lo tenía todo.
 */
final readonly class ExtractDocumentText
{
    public function __construct(
        private DocumentTextExtractorRegistry $extractors,
        private OcrEngineInterface $ocr,
        private int $minCharacters = 40,
        private int $maxCharacters = 200_000,
    ) {
    }

    public function __invoke(string $contents, string $mimeType, string $filename): DocumentText
    {
        if ('' === trim($contents)) {
            return DocumentText::none();
        }

        $extracted = $this->extractors->extract($contents, $mimeType, $filename);

        if (null !== $extracted && $this->isEnough($extracted['text'])) {
            return $this->build($extracted['text'], TextExtractionMethod::TEXT, $extracted['extractor']);
        }

        if ($this->ocr->isAvailable() && $this->ocr->supports($mimeType, $filename)) {
            $text = $this->ocr->extract($contents, $mimeType, $filename);

            if (null !== $text && $this->isEnough($text)) {
                return $this->build($text, TextExtractionMethod::OCR, $this->ocr->name());
            }
        }

        // Ni la capa de texto ni el OCR han dado algo utilizable. Se devuelve lo
        // poco que hubiera, marcado como tal, para que el pipeline pueda
        // explicar por qué el mensaje acaba en revisión.
        if (null !== $extracted && '' !== trim($extracted['text'])) {
            return $this->build($extracted['text'], TextExtractionMethod::TEXT, $extracted['extractor']);
        }

        return DocumentText::none();
    }

    private function isEnough(string $text): bool
    {
        return mb_strlen(trim($text)) >= $this->minCharacters;
    }

    private function build(string $text, TextExtractionMethod $method, string $extractor): DocumentText
    {
        $text = trim($text);
        $characters = mb_strlen($text);
        $truncated = $characters > $this->maxCharacters;

        if ($truncated) {
            $text = mb_substr($text, 0, $this->maxCharacters);
        }

        return new DocumentText($text, $method, $extractor, $characters, $truncated);
    }
}
