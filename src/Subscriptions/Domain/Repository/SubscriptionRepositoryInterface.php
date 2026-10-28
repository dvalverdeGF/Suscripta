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

    public function save(Subscription $subscription): void;

    public function remove(Subscription $subscription): void;
}
