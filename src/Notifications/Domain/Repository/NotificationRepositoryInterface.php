<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Repository;

use App\Notifications\Domain\Entity\Notification;
use Symfony\Component\Uid\Uuid;

interface NotificationRepositoryInterface
{
    public function find(Uuid $id): ?Notification;

    public function findByDedupKey(string $dedupKey): ?Notification;

    /**
     * @return list<Notification>
     */
    public function findForAlert(Uuid $alertId): array;

    /**
     * @return list<Notification>
     */
    public function findRecent(int $limit = 50): array;

    public function save(Notification $notification): void;
}
