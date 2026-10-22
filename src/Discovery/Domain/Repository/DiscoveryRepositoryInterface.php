<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Repository;

use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Entity\DiscoveryEvidence;
use App\Discovery\Domain\Enum\DiscoveryStatus;
use App\Discovery\Domain\Enum\DiscoveryType;
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
     * Bandeja de revisión de una organización, con filtros opcionales.
     *
     * El filtro por tipo se aplica en la consulta, no en memoria: la bandeja
     * puede tener cientos de propuestas y filtrarlas después de cargarlas
     * escondería justo lo que el usuario ha pedido ver.
     *
     * @return list<Discovery>
     */
    public function findForOrganization(
        ?DiscoveryStatus $status = null,
        int $limit = 100,
        ?DiscoveryType $type = null,
    ): array;

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
     * ¿Existe **alguna** propuesta con esta clave de deduplicación, sea del
     * estado que sea?
     *
     * Es lo que necesitan los detectores de inferencia (D-59). A diferencia del
     * emparejador del pipeline, que solo mira lo que está abierto, un barrido
     * sobre el inventario necesita saber si ya se propuso cualquier cosa: si
     * el usuario ya rechazó un duplicado, no hay que volver a preguntárselo
     * cada vez que se ejecute el barrido.
     */
    public function findByDedupKey(string $dedupKey): ?Discovery;

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
