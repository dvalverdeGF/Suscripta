<?php

declare(strict_types=1);

namespace App\Tests\Support\Notifications;

use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\AlertStatus;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Repository\AlertRepositoryInterface;

use function array_values;
use function count;

use const PHP_INT_MAX;

use Symfony\Component\Uid\Uuid;

/**
 * Repositorio de avisos en memoria.
 *
 * El generador es idempotente **porque consulta lo que ya existe**, así que
 * probarlo con un doble que siempre devuelve null no probaría nada. Este doble
 * guarda de verdad, y por eso puede demostrar que ejecutar dos veces no duplica.
 */
final class InMemoryAlertRepository implements AlertRepositoryInterface
{
    /** @var array<string, Alert> clave = identificador en formato RFC 4122 */
    private array $alerts = [];

    public int $flushCount = 0;

    public function find(Uuid $id): ?Alert
    {
        return $this->alerts[$id->toRfc4122()] ?? null;
    }

    public function findForOrganization(?AlertStatus $status = null, int $limit = 100): array
    {
        $result = [];

        foreach ($this->alerts as $alert) {
            if (null !== $status && $alert->getStatus() !== $status) {
                continue;
            }

            $result[] = $alert;

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    public function findOpen(int $limit = 50): array
    {
        return $this->findForOrganization(AlertStatus::OPEN, $limit);
    }

    public function findByDedupKey(string $dedupKey): ?Alert
    {
        foreach ($this->alerts as $alert) {
            if ($alert->getDedupKey() === $dedupKey) {
                return $alert;
            }
        }

        return null;
    }

    public function findOpenByType(AlertType $type): array
    {
        $result = [];

        foreach ($this->alerts as $alert) {
            if ($alert->getType() === $type && $alert->isOpen()) {
                $result[] = $alert;
            }
        }

        return $result;
    }

    public function findOpenForService(Uuid $serviceId): array
    {
        $result = [];

        foreach ($this->alerts as $alert) {
            if ($alert->isOpen() && $alert->getServiceId()?->toRfc4122() === $serviceId->toRfc4122()) {
                $result[] = $alert;
            }
        }

        return $result;
    }

    public function countOpen(): int
    {
        return count($this->findOpen(PHP_INT_MAX));
    }

    public function countByStatus(): array
    {
        $counts = [];

        foreach ($this->alerts as $alert) {
            $key = $alert->getStatus()->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    public function save(Alert $alert, bool $flush = true): void
    {
        $this->alerts[$alert->getId()->toRfc4122()] = $alert;
    }

    public function remove(Alert $alert, bool $flush = true): void
    {
        unset($this->alerts[$alert->getId()->toRfc4122()]);
    }

    public function flush(): void
    {
        ++$this->flushCount;
    }

    /**
     * @return list<Alert>
     */
    public function all(): array
    {
        return array_values($this->alerts);
    }
}
