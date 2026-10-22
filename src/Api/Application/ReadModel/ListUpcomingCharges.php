<?php

declare(strict_types=1);

namespace App\Api\Application\ReadModel;

use App\Api\Application\ApiCollection;
use App\Services\Domain\Repository\ServiceViewRepositoryInterface;
use App\Shared\Application\Clock;

use function sprintf;


/**
 * Próximos cobros de la organización.
 *
 * La ventana se cuenta **desde hoy**: «los cobros de los próximos 30 días»,
 * que es lo que pregunta un integrador para alimentar un calendario de pagos.
 */
final readonly class ListUpcomingCharges
{
    public function __construct(
        private ServiceViewRepositoryInterface $services,
        private Clock $clock,
    ) {
    }

    public function __invoke(int $days = 30, int $page = 1, int $perPage = 25): ApiCollection
    {
        $until = $this->clock->now()->modify(sprintf('+%d days', max(1, $days)));

        $result = $this->services->findUpcomingChargesPage($until, $page, $perPage);

        $items = [];
        foreach ($result->items as $service) {
            $items[] = ResourceMapper::service($service);
        }

        return ApiCollection::of($items, $result->pagination);
    }
}
