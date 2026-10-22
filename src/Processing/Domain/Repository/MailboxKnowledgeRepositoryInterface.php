<?php

declare(strict_types=1);

namespace App\Processing\Domain\Repository;

use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Processing\Domain\Enum\MailboxKnowledgeType;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

interface MailboxKnowledgeRepositoryInterface
{
    public function find(Uuid $id): ?MailboxKnowledgeEntry;

    /**
     * Todo lo aprendido de un buzón, para la pantalla de conocimiento.
     *
     * @return list<MailboxKnowledgeEntry>
     */
    public function findForAccount(Uuid $emailAccountId): array;

    /**
     * @return list<MailboxKnowledgeEntry>
     */
    public function findByType(Uuid $emailAccountId, MailboxKnowledgeType $type): array;

    /**
     * Búsqueda exacta, que es la que usa el pipeline para no repetir
     * conocimiento ya aprendido.
     */
    public function findByValue(Uuid $emailAccountId, MailboxKnowledgeType $type, string $value): ?MailboxKnowledgeEntry;

    /**
     * Conocimiento que no se ha usado nunca y es viejo: candidato a poda.
     *
     * @return list<MailboxKnowledgeEntry>
     */
    public function findStale(DateTimeImmutable $olderThan, int $maxHitCount = 0): array;

    public function save(MailboxKnowledgeEntry $entry, bool $flush = true): void;

    /**
     * @param iterable<MailboxKnowledgeEntry> $entries
     */
    public function saveAll(iterable $entries): void;

    /**
     * Vuelca los cambios pendientes sin volver a persistir las entidades.
     */
    public function flush(): void;

    public function remove(MailboxKnowledgeEntry $entry, bool $flush = true): void;

    /**
     * Borra el conocimiento aprendido de una cuenta de correo.
     *
     * @return int número de entradas eliminadas
     */
    public function removeForAccount(Uuid $emailAccountId): int;

    /**
     * Borra todo el conocimiento aprendido de una organización.
     *
     * @return int número de entradas eliminadas
     */
    public function removeForOrganization(Uuid $organizationId): int;

    /**
     * Poda las entradas que no se han usado desde hace tiempo.
     *
     * @return int número de entradas eliminadas
     */
    public function pruneUnusedBefore(DateTimeImmutable $olderThan, int $limit = 500): int;

    /**
     * Todo el conocimiento aprendido de una organización concreta.
     *
     * @return list<MailboxKnowledgeEntry>
     */
    public function findForOrganization(Uuid $organizationId): array;
}
