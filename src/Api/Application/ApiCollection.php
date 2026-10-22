<?php

declare(strict_types=1);

namespace App\Api\Application;

use App\Shared\Application\Pagination;

/**
 * Una colección de la API, lista para serializar.
 *
 * Toda respuesta de listado tiene la misma forma: `data` con los elementos y
 * `meta` con la paginación. Un integrador puede escribir un cliente que
 * entienda **todas** las listas de la API con un solo caso, en vez de uno por
 * endpoint.
 */
final readonly class ApiCollection
{
    /**
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        public array $items,
        public Pagination $pagination,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public static function of(array $items, Pagination $pagination): self
    {
        return new self($items, $pagination);
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'data' => $this->items,
            'meta' => $this->pagination->toArray(),
        ];
    }
}
