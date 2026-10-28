<?php

declare(strict_types=1);

namespace App\Subscriptions\Domain\Repository;

use App\Subscriptions\Domain\Entity\Subscription;
use Symfony\Component\Uid\Uuid;

interface SubscriptionRepositoryInterface
{
    public function find(Uuid $id): ?Subscription;

    /**
     * @return list<Subscription>
     */
    public function findForOrganization(SubscriptionFilters $filters = new SubscriptionFilters()): array;

    /**
     * La suscripción viva de un servicio del inventario, si la hay.
     *
     * Se usa para enseñar en la ficha del servicio que hay un contrato detrás,
     * sin que el módulo `Services` tenga que saber que existe este.
     */
    public function findLiveForService(Uuid $serviceId): ?Subscription;

    /**
     * @return array<string, int> estado => número de suscripciones
     */
    public function countByStatus(): array;

    /**
     * Suscripciones a las que todavía no se les ha enlazado un plan del
     * catálogo, de **todas** las organizaciones.
     *
     * Es la única consulta del módulo que no se limita al inquilino actual, y
     * solo la usa el relleno de la fase 22 (`app:catalog:link-subscriptions`),
     * que corre desde la consola, donde no hay organización en contexto y el
     * filtro de tenant no restringe (D-80).
     *
     * @return list<Subscription>
     */
    public function findWithoutPlan(): array;

    public function save(Subscription $subscription): void;

    public function remove(Subscription $subscription): void;
}
