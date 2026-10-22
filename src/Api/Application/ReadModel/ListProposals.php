<?php

declare(strict_types=1);

namespace App\Api\Application\ReadModel;

use App\Api\Application\ApiCollection;
use App\Discovery\Domain\Enum\DiscoveryStatus;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryViewRepositoryInterface;

/**
 * Listado de propuestas de descubrimiento.
 *
 * Son las suscripciones que el pipeline ha detectado y que todavía están
 * pendientes de decisión. Un integrador puede confirmarlas desde la API
 * (`POST /api/v1/proposals/{id}/confirm`) si su integración decide el alta de
 * servicios por su cuenta.
 */
final readonly class ListProposals
{
    public function __construct(private DiscoveryViewRepositoryInterface $proposals)
    {
    }

    public function __invoke(
        ?DiscoveryStatus $status = null,
        ?DiscoveryType $type = null,
        int $page = 1,
        int $perPage = 25,
    ): ApiCollection {
        $result = $this->proposals->findPage($status, $type, $page, $perPage);

        $items = [];
        foreach ($result->items as $proposal) {
            $items[] = ResourceMapper::proposal($proposal);
        }

        return ApiCollection::of($items, $result->pagination);
    }
}
