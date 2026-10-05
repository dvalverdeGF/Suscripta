<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Repository;

use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\AlertStatus;
use App\Notifications\Domain\Enum\AlertType;
use Symfony\Component\Uid\Uuid;

interface AlertRepositoryInterface
{
    public function find(Uuid $id): ?Alert;

    /**
     * @return list<Alert>
     */
    public function findForOrganization(?AlertStatus $status = null, int $limit = 100): array;

    /**
     * @return list<Alert>
     */
    public function findOpen(int $limit = 50): array;

    public function findByDedupKey(string $dedupKey): ?Alert;

    /**
     * Avisos abiertos de un tipo concreto, para poder resolverlos cuando la
     * situación que los motivó ya no se da.
     *
     * @return list<Alert>
     */
    public function findOpenByType(AlertType $type): array;

    /**
     * @return list<Alert>
     */
    public function findOpenForService(Uuid $serviceId): array;

    public function countOpen(): int;

    /**
     * @return array<string, int> clave = valor del enum de estado
     */
    public function countByStatus(): array;

    /**
     * @param bool $flush `false` para agrupar varias escrituras y volcar una
     *                    sola vez con flush()
     */
    public function save(Alert $alert, bool $flush = true): void;

    public function remove(Alert $alert, bool $flush = true): void;

    /**
     * Vuelca los cambios pendientes. El generador crea y resuelve avisos en
     * lote y no tiene sentido escribir en la base de datos uno a uno.
     */
    public function flush(): void;
}
