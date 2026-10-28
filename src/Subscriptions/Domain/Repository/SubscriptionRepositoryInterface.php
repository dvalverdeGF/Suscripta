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

    /**
     * Busca por la referencia de la pasarela, **sin** limitarse al inquilino
     * actual.
     *
     * La usa el webhook de cobro, que llega sin sesión y solo sabe un
     * `sub_…`: tiene que encontrar la suscripción antes de saber de qué
     * organización es. Es la segunda consulta del módulo que se salta el
     * filtro, y por el mismo motivo que la primera: no hay organización en
     * contexto, así que el filtro no restringe (D-80).
     */
    public function findByExternalReference(string $externalReference): ?Subscription;

    /**
     * Suscripciones cobradas por una pasarela y todavía no cerradas, de todas
     * las organizaciones. Es la lista que recorre la conciliación
     * (`app:billing:reconcile`) para preguntar por cada una.
     *
     * @return list<Subscription>
     */
    public function findBillable(): array;

    public function save(Subscription $subscription): void;

    public function remove(Subscription $subscription): void;
}
