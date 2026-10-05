<?php

declare(strict_types=1);

namespace App\Processing\Application\Provider;

use App\Processing\Domain\Provider\ProviderParserInterface;

use function array_keys;
use function array_values;
use function implode;
use function iterator_to_array;
use function sprintf;

/**
 * Catálogo de parsers de código disponibles (ARCHITECTURE.md §13.7).
 *
 * `ProviderParser.key` es una cadena en base de datos y aquí se convierte en un
 * servicio. Esa indirección es lo que permite que el conocimiento de un
 * proveedor viva en la base de datos (y se pueda aprender o corregir sin
 * desplegar) mientras el código sigue siendo código.
 */
final readonly class ProviderParserRegistry
{
    /** @var array<string, ProviderParserInterface> */
    private array $parsers;

    /**
     * @param iterable<ProviderParserInterface> $parsers
     */
    public function __construct(iterable $parsers)
    {
        $indexed = [];

        foreach (iterator_to_array($parsers, false) as $parser) {
            $indexed[$parser->key()] = $parser;
        }

        $this->parsers = $indexed;
    }

    public function has(string $key): bool
    {
        return isset($this->parsers[$key]);
    }

    public function get(string $key): ?ProviderParserInterface
    {
        return $this->parsers[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_values(array_keys($this->parsers));
    }

    public function describe(): string
    {
        return [] === $this->parsers
            ? 'No hay parsers de código registrados.'
            : sprintf('Parsers disponibles: %s.', implode(', ', $this->keys()));
    }
}
