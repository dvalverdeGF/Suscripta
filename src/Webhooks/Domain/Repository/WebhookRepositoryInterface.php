<?php

declare(strict_types=1);

namespace App\Webhooks\Domain\Repository;

use App\Webhooks\Domain\Entity\Webhook;
use App\Webhooks\Domain\Enum\WebhookEvent;
use Symfony\Component\Uid\Uuid;

interface WebhookRepositoryInterface
{
    public function find(Uuid $id): ?Webhook;

    /**
     * @return list<Webhook>
     */
    public function findForOrganization(Uuid $organizationId): array;

    /**
     * Suscriptores activos de la organización que escuchan este evento.
     *
     * @return list<Webhook>
     */
    public function findActiveSubscribers(Uuid $organizationId, WebhookEvent $event): array;

    public function save(Webhook $webhook, bool $flush = true): void;

    public function remove(Webhook $webhook, bool $flush = true): void;

    public function removeForOrganization(Uuid $organizationId): int;
}
