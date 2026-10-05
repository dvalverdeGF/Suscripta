<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Repository;

use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Entity\DiscoveryEvidence;
use App\Discovery\Domain\Enum\DiscoveryStatus;
use Symfony\Component\Uid\Uuid;

interface DiscoveryRepositoryInterface
{
    public function find(Uuid $id): ?Discovery;

    /**
     * Bandeja de revisión: lo que el usuario tiene pendiente de decidir.
     *
     * @return list<Discovery>
     */
    public function findPending(int $limit = 50): array;

    /**
     * @return list<Discovery>
     */
    public function findForOrganization(?DiscoveryStatus $status = null, int $limit = 100): array;

    /**
     * Deduplicación entre cuentas (D-27): ¿ya hay una propuesta abierta para
     * este mismo proveedor, importe y periodicidad?
     */
    public function findOpenByDedupKey(string $dedupKey): ?Discovery;

    public function countPending(): int;

    /**
     * @return array<string, int> estado => número de propuestas
     */
    public function countByStatus(): array;

    public function save(Discovery $discovery, bool $flush = true): void;

    public function saveEvidence(DiscoveryEvidence $evidence, bool $flush = true): void;

    /**
     * @return list<DiscoveryEvidence>
     */
    public function findEvidence(Uuid $discoveryId): array;
}
