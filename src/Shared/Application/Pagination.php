<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Datos de paginación de una colección.
 *
 * Vive en `Shared` porque la paginación no es un concepto de un módulo
 * concreto: lo usan los read models de `Services`, `Notifications`,
 * `Documents`, `Discovery` y la API pública, y todos deben contar y saltar
 * igual para que el mismo `page`/`per_page` signifique lo mismo en todas
 * partes.
 *
 * El formato (`page`, `per_page`, `total`, `total_pages`) es el que consume
 * directamente la capa HTTP de la API, así que se fija aquí y no en cada
 * controlador.
 */
final readonly class Pagination
{
    public const DEFAULT_PER_PAGE = 25;
    public const MAX_PER_PAGE = 100;

    public function __construct(
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    /**
     * Normaliza los parámetros que llegan de fuera (query string).
     *
     * Una página negativa o un `per_page` de 10000 no son un error de
     * validación: son valores absurdos que se ajustan a un rango utilizable.
     * Devolver un 422 por eso obligaría a los integradores a acertar a la
     * primera, y no aporta nada.
     */
    public static function fromRequest(int $page, int $perPage): self
    {
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), self::MAX_PER_PAGE);

        return new self($page, $perPage, 0);
    }

    public function withTotal(int $total): self
    {
        return new self($this->page, $this->perPage, $total);
    }

    public function getTotalPages(): int
    {
        if ($this->perPage <= 0) {
            return 0;
        }

        return (int) ceil($this->total / $this->perPage);
    }

    public function getOffset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->getTotalPages();
    }

    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }

    /**
     * @return array{page: int, per_page: int, total: int, total_pages: int, has_next: bool, has_previous: bool}
     */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'total_pages' => $this->getTotalPages(),
            'has_next' => $this->hasNextPage(),
            'has_previous' => $this->hasPreviousPage(),
        ];
    }
}
