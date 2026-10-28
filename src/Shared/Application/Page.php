<?php

declare(strict_types=1);

namespace App\Shared\Application;

use function count;

/**
 * Página de resultados de un read model.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public Pagination $pagination,
    ) {
    }

    /**
     * @template U
     *
     * @param list<U> $items
     *
     * @return self<U>
     */
    public static function of(array $items, Pagination $pagination): self
    {
        return new self($items, $pagination);
    }

    public function isEmpty(): bool
    {
        return [] === $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }
}
