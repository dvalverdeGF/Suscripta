<?php

declare(strict_types=1);

namespace App\Services\Domain\Repository;

use App\Services\Domain\Dto\ServiceView;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Repositorio de **lectura** del inventario de servicios.
 *
 * Existe separado del `ServiceRepositoryInterface` a propósito (Fase 12,
 * ARCHITECTURE.md §3). El repositorio de escritura expone entidades porque hay
 * que poder mutarlas; el de lectura expone vistas porque todo lo demás
 * —dashboard, alertas, pipeline, API— solo necesita *leer*.
 *
 * La diferencia no es cosmética: con un solo repositorio, cualquier módulo que
 * leyera un servicio acabaría importando `Service`, `ServicePrice` y
 * `ServiceEvent`, y un campo nuevo en la entidad obligaría a tocar medio
 * proyecto. Con dos, el modelo de lectura cambia cuando cambia la entidad, y
 * solo dentro del módulo `Services`.
 */
interface ServiceViewRepositoryInterface
{
    /**
     * @return list<ServiceView>
     */
    public function findAll(ServiceFilters $filters = new ServiceFilters()): array;

    public function findOne(Uuid $id): ?ServiceView;

    /**
     * Servicios cuyo próximo cobro cae dentro de la ventana indicada.
     *
     * La vista ya trae `nextChargeAt`; este método existe para que quien
     * necesite la lista no tenga que filtrar en PHP ni montar la consulta.
     *
     * @return list<ServiceView>
     */
    public function findUpcomingCharges(DateTimeImmutable $until, int $limit = 10): array;

    /**
     * @return list<ServiceView>
     */
    public function findUpcomingRenewals(DateTimeImmutable $until, int $limit = 10): array;
}
