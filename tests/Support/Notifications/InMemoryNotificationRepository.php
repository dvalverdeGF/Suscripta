<?php

declare(strict_types=1);

namespace App\Tests\Support\Notifications;

use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;

use function array_slice;
use function array_values;
use function count;

use Symfony\Component\Uid\Uuid;

/**
 * Repositorio de entregas en memoria.
 *
 * La idempotencia del despachador depende de que se pueda consultar lo ya
 * entregado, así que el doble tiene que recordar de verdad.
 */
final class InMemoryNotificationRepository implements NotificationRepositoryInterface
{
    /** @var array<string, Notification> */
    private array $notifications = [];

    public function find(Uuid $id): ?Notification
    {
        return $this->notifications[$id->toRfc4122()] ?? null;
    }

    public function findByDedupKey(string $dedupKey): ?Notification
    {
        foreach ($this->notifications as $notification) {
            if ($notification->getDedupKey() === $dedupKey) {
                return $notification;
            }
        }

        return null;
    }

    public function findForAlert(Uuid $alertId): array
    {
        $result = [];

        foreach ($this->notifications as $notification) {
            if ($notification->getAlertId()->toRfc4122() === $alertId->toRfc4122()) {
                $result[] = $notification;
            }
        }

        return $result;
    }

    public function findRecent(int $limit = 50): array
    {
        return array_slice(array_values($this->notifications), 0, $limit);
    }

    public function save(Notification $notification): void
    {
        $this->notifications[$notification->getId()->toRfc4122()] = $notification;
    }

    /**
     * @return list<Notification>
     */
    public function all(): array
    {
        return array_values($this->notifications);
    }

    public function count(): int
    {
        return count($this->notifications);
    }
}
