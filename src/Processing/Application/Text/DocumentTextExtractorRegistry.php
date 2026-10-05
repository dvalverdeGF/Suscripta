<?php

declare(strict_types=1);

namespace App\Processing\Application\Text;

use App\Processing\Domain\Text\DocumentTextExtractorInterface;

use function array_values;
use function iterator_to_array;
use function usort;

/**
 * Cadena de extractores de texto (ARCHITECTURE.md §13.6).
 *
 * Se prueban en orden y gana el primero que reconozca el formato. El orden lo
 * decide `priority()`, no el orden en que el contenedor descubra los archivos:
 * un extractor específico (PDF) tiene que ir antes que uno genérico que también
 * acepte el formato.
 */
final readonly class DocumentTextExtractorRegistry
{
    /** @var list<DocumentTextExtractorInterface> */
    private array $extractors;

    /**
     * @param iterable<DocumentTextExtractorInterface> $extractors
     */
    public function __construct(iterable $extractors)
    {
        $ordered = array_values(iterator_to_array($extractors, false));

        usort(
            $ordered,
            static fn (DocumentTextExtractorInterface $a, DocumentTextExtractorInterface $b): int => $b->priority() <=> $a->priority(),
        );

        $this->extractors = $ordered;
    }

    /**
     * @return list<DocumentTextExtractorInterface>
     */
    public function all(): array
    {
        return $this->extractors;
    }

    public function supports(string $mimeType, string $filename): bool
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($mimeType, $filename)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Texto del primer extractor que reconozca el formato.
     *
     * Devuelve también el nombre del extractor que lo consiguió, porque el log
     * de procesamiento tiene que poder decir con qué se leyó cada documento.
     *
     * @return array{text: string, extractor: string}|null
     */
    public function extract(string $contents, string $mimeType, string $filename): ?array
    {
        foreach ($this->extractors as $extractor) {
            if (!$extractor->supports($mimeType, $filename)) {
                continue;
            }

            $text = $extractor->extract($contents, $mimeType, $filename);

            if (null === $text) {
                continue;
            }

            return ['text' => $text, 'extractor' => $extractor->name()];
        }

        return null;
    }
}
