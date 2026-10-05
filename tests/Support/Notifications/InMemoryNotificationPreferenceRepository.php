<?php

declare(strict_types=1);

namespace App\Tests\Support\Notifications;

use App\Notifications\Domain\Entity\NotificationPreference;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Enum\NotificationChannel;
use App\Notifications\Domain\Repository\NotificationPreferenceRepositoryInterface;

use function array_values;
use function sprintf;

use Symfony\Component\Uid\Uuid;

/**
 * Preferencias en memoria, indexadas por «usuario|tipo|canal».
 */
final class InMemoryNotificationPreferenceRepository implements NotificationPreferenceRepositoryInterface
{
    /** @var array<string, NotificationPreference> */
    private array $preferences = [];

    public function find(Uuid $id): ?NotificationPreference
    {
        foreach ($this->preferences as $preference) {
            if ($preference->getId()->toRfc4122() === $id->toRfc4122()) {
                return $preference;
            }
        }

        return null;
    }

    public function findOne(Uuid $userId, AlertType $type, NotificationChannel $channel): ?NotificationPreference
    {
        return $this->preferences[self::key($userId, $type, $channel)] ?? null;
    }

    public function findForUser(Uuid $userId): array
    {
        $result = [];

        foreach ($this->preferences as $preference) {
            if ($preference->getUserId()->toRfc4122() === $userId->toRfc4122()) {
                $result[] = $preference;
            }
        }

        return $result;
    }

    public function findEnabledMapForUser(Uuid $userId): array
    {
        $map = [];

        foreach ($this->findForUser($userId) as $preference) {
            $map[sprintf('%s|%s', $preference->getAlertType()->value, $preference->getChannel()->value)] = $preference->isEnabled();
        }

        return $map;
    }

    public function save(NotificationPreference $preference): void
    {
        $this->preferences[self::key($preference->getUserId(), $preference->getAlertType(), $preference->getChannel())] = $preference;
    }

    public function remove(NotificationPreference $preference): void
    {
        unset($this->preferences[self::key($preference->getUserId(), $preference->getAlertType(), $preference->getChannel())]);
    }

    /**
     * @return list<NotificationPreference>
     */
    public function all(): array
    {
        return array_values($this->preferences);
    }

    private static function key(Uuid $userId, AlertType $type, NotificationChannel $channel): string
    {
        return sprintf('%s|%s|%s', $userId->toRfc4122(), $type->value, $channel->value);
    }
}
