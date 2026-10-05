<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Repository;

use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use Symfony\Component\Uid\Uuid;

interface EmailMessageRepositoryInterface
{
    public function find(Uuid $id): ?EmailMessage;

    /**
     * Clave primaria de deduplicación: `(cuenta, carpeta, uid)` (§13.2).
     */
    public function findByAccountFolderUid(Uuid $emailAccountId, string $folder, int $uid): ?EmailMessage;

    /**
     * Clave secundaria: detecta el mismo mensaje movido de carpeta.
     */
    public function findByAccountAndMessageId(Uuid $emailAccountId, string $messageId): ?EmailMessage;

    /**
     * Caché de extracción: si ya hay un mensaje con este contenido, no se vuelve
     * a pagar por analizarlo (D-37).
     */
    public function findByContentHash(string $contentHash): ?EmailMessage;

    /**
     * @return list<EmailMessage>
     */
    public function findForOrganization(?MessageProcessingState $state = null, int $limit = 50): array;

    /**
     * Mensajes de un buzón que el pipeline todavía puede avanzar (§13.5).
     *
     * @return list<EmailMessage>
     */
    public function findPendingForAccount(Uuid $emailAccountId, int $limit = 200): array;

    /**
     * @return array<string, int> estado => número de mensajes
     */
    public function countByState(): array;

    public function countForOrganization(): int;

    public function countForAccount(Uuid $emailAccountId): int;

    /**
     * Recuento de mensajes de todos los buzones de la organización, indexado
     * por id de cuenta. Evita una consulta por buzón al pintar listados.
     *
     * @return array<string, int> id de cuenta => número de mensajes
     */
    public function countByAccount(): array;

    public function save(EmailMessage $message, bool $flush = true): void;

    /**
     * @param iterable<EmailMessage> $messages
     */
    public function saveAll(iterable $messages): void;

    /**
     * Vuelca los cambios pendientes. Se usa al final de una sincronización,
     * donde se persisten cientos de mensajes en una sola transacción.
     */
    public function flush(): void;

    public function removeForAccount(Uuid $emailAccountId): int;
}
