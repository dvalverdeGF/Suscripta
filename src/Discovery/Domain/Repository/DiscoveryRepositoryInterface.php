<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Repository;

use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Entity\DiscoveryEvidence;
use App\Discovery\Domain\Enum\DiscoveryStatus;
use DateTimeImmutable;
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
     * Deduplicación entre cuentas (D-27): ¿hay ya una propuesta para este
     * mismo proveedor, importe y periodicidad?
     *
     * Bloquean la propuesta nueva dos situaciones:
     *   - una propuesta viva (`pending`), para no duplicar lo que ya está en
     *     la bandeja;
     *   - una propuesta ignorada desde `$ignoredSince`, porque el usuario ya
     *     dijo que no le interesa (D-45).
     *
     * Una propuesta confirmada no bloquea: el servicio ya existe y el
     * emparejamiento se encarga de asociar los nuevos documentos.
     */
    public function findBlockingByDedupKey(string $dedupKey, DateTimeImmutable $ignoredSince): ?Discovery;

    /**
     * Propuestas caducadas: las que llevan demasiado tiempo en la bandeja sin
     * que el usuario decida nada.
     *
     * @return list<Discovery>
     */
    public function findExpired(DateTimeImmutable $before, int $limit = 100): array;

    public function countPending(): int;

    /**
     * @return array<string, int> estado => número de propuestas
     */
    public function countByStatus(): array;

    public function save(Discovery $discovery, bool $flush = true): void;

    /**
     * Vuelca los cambios pendientes. Existe para las operaciones por lotes,
     * donde confirmar uno a uno multiplicaría las idas al servidor.
     */
    public function flush(): void;

    public function saveEvidence(DiscoveryEvidence $evidence, bool $flush = true): void;

    /**
     * @return list<DiscoveryEvidence>
     */
    public function findEvidence(Uuid $discoveryId): array;
}
