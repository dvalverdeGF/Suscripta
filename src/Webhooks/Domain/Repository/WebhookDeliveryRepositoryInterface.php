<?php

declare(strict_types=1);

namespace App\Webhooks\Domain\Repository;

use App\Webhooks\Domain\Entity\WebhookDelivery;
use Symfony\Component\Uid\Uuid;

interface WebhookDeliveryRepositoryInterface
{
    public function find(Uuid $id): ?WebhookDelivery;

    /**
     * @return list<WebhookDelivery>
     */
    public function findForWebhook(Uuid $webhookId, int $limit = 50): array;

    /**
     * Entregas pendientes cuya hora de intento ya ha llegado.
     *
     * @return list<WebhookDelivery>
     */
    public function findDue(int $limit = 100): array;

    /**
     * Entregas pendientes que llevan más de `$olderThan` sin conseguirse.
     *
     * @return list<WebhookDelivery>
     */
    public function findAbandonable(int $days, int $limit = 500): array;

    public function countPending(Uuid $webhookId): int;

    public function save(WebhookDelivery $delivery, bool $flush = true): void;

    public function removeForWebhook(Uuid $webhookId): int;
}
