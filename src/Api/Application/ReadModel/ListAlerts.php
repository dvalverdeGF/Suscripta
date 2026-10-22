<?php

declare(strict_types=1);

namespace App\Api\Application\ReadModel;

use App\Api\Application\ApiCollection;
use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertStatus;
use App\Notifications\Domain\Repository\AlertViewRepositoryInterface;

/**
 * Listado de alertas de la organización.
 *
 * Es el endpoint que un integrador consulta para enterarse de los cambios:
 * cobros próximos, renovaciones, cambios de precio y avisos del pipeline.
 */
final readonly class ListAlerts
{
    public function __construct(private AlertViewRepositoryInterface $alerts)
    {
    }

    public function __invoke(
        ?AlertStatus $status = null,
        ?AlertSeverity $severity = null,
        ?string $search = null,
        ?string $sort = null,
        int $page = 1,
        int $perPage = 25,
    ): ApiCollection {
        $result = $this->alerts->findPage($status, $severity, $search, $sort, $page, $perPage);

        $items = [];
        foreach ($result->items as $alert) {
            $items[] = ResourceMapper::alert($alert);
        }

        return ApiCollection::of($items, $result->pagination);
    }
}
