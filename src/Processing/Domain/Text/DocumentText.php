<?php

declare(strict_types=1);

namespace App\Processing\Domain\Text;

/**
 * Resultado de leer un documento (ARCHITECTURE.md §13.6).
 *
 * Lleva el método además del texto porque el pipeline necesita poder explicar
 * de dónde salió cada dato: "el importe se leyó del PDF" y "el importe se leyó
 * de una foto borrosa" no merecen la misma confianza.
 */
final readonly class DocumentText
{
    public function __construct(
        public string $text,
        public TextExtractionMethod $method,
        public string $extractor,
        public int $characters = 0,
        public bool $truncated = false,
    ) {
    }

    public static function none(): self
    {
        return new self('', TextExtractionMethod::NONE, 'none');
    }

    public function isEmpty(): bool
    {
        return '' === trim($this->text);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method->value,
            'extractor' => $this->extractor,
            'characters' => $this->characters,
            'truncated' => $this->truncated,
        ];
    }
}
