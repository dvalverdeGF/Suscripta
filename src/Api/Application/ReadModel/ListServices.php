<?php

declare(strict_types=1);

namespace App\Api\Application\ReadModel;

use App\Api\Application\ApiCollection;
use App\Services\Domain\Enum\ServiceStatus;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceViewRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Listado de servicios de la organización, paginado.
 *
 * Los filtros llegan ya validados desde la capa HTTP: aquí solo se traducen
 * al contrato del módulo `Services`. La API no **decide** qué es un filtro
 * válido (eso es cosa de la validación), pero tampoco filtra a su manera: usa
 * el mismo `ServiceFilters` que usa el backoffice, de modo que los dos
 * ven lo mismo.
 */
final readonly class ListServices
{
    public function __construct(private ServiceViewRepositoryInterface $services)
    {
    }

    public function __invoke(
        ?ServiceStatus $status = null,
        ?Uuid $categoryId = null,
        ?Uuid $providerId = null,
        ?string $search = null,
        ?string $sort = null,
        int $page = 1,
        int $perPage = 25,
    ): ApiCollection {
        $filters = new ServiceFilters(
            status: $status,
            categoryId: $categoryId,
            providerId: $providerId,
            search: $search,
            sort: $sort,
        );

        $result = $this->services->findPage($filters, $page, $perPage);

        $items = [];
        foreach ($result->items as $service) {
            $items[] = ResourceMapper::service($service);
        }

        return ApiCollection::of($items, $result->pagination);
    }
}
