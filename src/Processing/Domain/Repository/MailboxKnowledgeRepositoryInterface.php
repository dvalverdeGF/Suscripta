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
}
