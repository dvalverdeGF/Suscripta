<?php

declare(strict_types=1);

namespace App\Services\Domain\Repository;

use App\Services\Domain\Enum\ServiceStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Filtros de listado del inventario de servicios.
 *
 * Se pasan como objeto y no como parámetros sueltos porque la pantalla de
 * servicios los combina libremente y el repositorio debe poder ignorar los que
 * vengan a null.
 */
final readonly class ServiceFilters
{
    public function __construct(
        public ?ServiceStatus $status = null,
        public ?Uuid $categoryId = null,
        public ?Uuid $providerId = null,
        public ?string $search = null,
        public ?string $sort = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }
}
