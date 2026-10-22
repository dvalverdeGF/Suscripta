<?php

declare(strict_types=1);

namespace App\Services\Domain\Repository;

use App\Services\Domain\Entity\Service;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

interface ServiceRepositoryInterface
{
    public function find(Uuid $id): ?Service;

    /**
     * @return list<Service>
     */
    public function findForOrganization(ServiceFilters $filters = new ServiceFilters()): array;

    /**
     * Servicios activos con próximo cobro dentro de la ventana indicada.
     *
     * @return list<Service>
     */
    public function findUpcomingCharges(DateTimeImmutable $until, int $limit = 10): array;

    /**
     * Servicios activos con renovación dentro de la ventana indicada.
     *
     * @return list<Service>
     */
    public function findUpcomingRenewals(DateTimeImmutable $until, int $limit = 10): array;

    /**
     * @return array<string, int> estado => número de servicios
     */
    public function countByStatus(): array;

    public function countAll(): int;

    public function save(Service $service, bool $flush = true): void;

    public function remove(Service $service, bool $flush = true): void;

    /**
     * Borra todos los servicios de una organización.
     *
     * Se borran entidad a entidad porque `Service` arrastra en cascada sus
     * precios y sus eventos: un borrado masivo dejaría esas filas huérfanas.
     *
     * @return int número de servicios eliminados
     */
    public function removeForOrganization(Uuid $organizationId): int;

    /**
     * Todos los servicios de una organización concreta.
     *
     * A diferencia de `findForOrganization()`, que usa la organización activa
     * del contexto, este método toma la organización como dato: lo necesitan
     * la exportación de datos y la purga, que pueden ejecutarse en un
     * contexto distinto al del tenant activo.
     *
     * @return list<Service>
     */
    public function findAllForOrganization(Uuid $organizationId): array;
}
