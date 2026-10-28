<?php

declare(strict_types=1);

namespace App\Subscriptions\Domain\Repository;

use App\Subscriptions\Domain\Enum\SubscriptionStatus;

use function in_array;

use Symfony\Component\Uid\Uuid;

/**
 * Filtros del listado de suscripciones.
 *
 * Es un objeto y no una lista de parámetros porque el listado ya tiene cinco y
 * añadir el sexto habría cambiado la firma del repositorio en cada fase.
 */
final readonly class SubscriptionFilters
{
    public const array SORTS = ['name', 'renewal', 'created'];

    public function __construct(
        public ?SubscriptionStatus $status = null,
        public ?Uuid $planId = null,
        public ?string $search = null,
        public string $sort = 'renewal',
    ) {
    }

    public function normalizedSort(): string
    {
        return in_array($this->sort, self::SORTS, true) ? $this->sort : 'renewal';
    }
}
