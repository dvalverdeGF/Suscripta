<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Entity;

use App\Mailbox\Domain\Enum\SyncRunStatus;
use App\Shared\Domain\Contract\TenantAwareInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Una pasada de sincronización sobre un buzón (ARCHITECTURE.md §4.5).
 *
 * Existe para poder responder "¿qué pasó en la última sincronización?" sin
 * tener que reconstruirlo a partir de los mensajes: cuántos se vieron, cuántos
 * se descartaron por el filtro determinista y cuántos descubrimientos salieron.
 */
#[ORM\Entity]
#[ORM\Table(name: 'email_sync_run')]
#[ORM\Index(name: 'idx_email_sync_run_account', columns: ['email_account_id', 'started_at'])]
class EmailSyncRun implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    #[ORM\Column(name: 'email_account_id', type: 'uuid')]
    private Uuid $emailAccountId;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'finished_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: SyncRunStatus::class)]
    private SyncRunStatus $status = SyncRunStatus::RUNNING;

    #[ORM\Column(name: 'messages_seen', type: Types::INTEGER)]
    private int $messagesSeen = 0;

    #[ORM\Column(name: 'messages_processed', type: Types::INTEGER)]
    private int $messagesProcessed = 0;

    #[ORM\Column(name: 'messages_skipped', type: Types::INTEGER)]
    private int $messagesSkipped = 0;

    /**
     * Mensajes recuperados del histórico en esta pasada. Se cuenta aparte
     * porque el backfill es progresivo: una pasada normal no recupera nada y
     * una de backfill no trae correo nuevo.
     */
    #[ORM\Column(name: 'messages_backfilled', type: Types::INTEGER)]
    private int $messagesBackfilled = 0;

    #[ORM\Column(name: 'discoveries_created', type: Types::INTEGER)]
    private int $discoveriesCreated = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    public function __construct(Uuid $organizationId, Uuid $emailAccountId, DateTimeImmutable $startedAt)
    {
        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->emailAccountId = $emailAccountId;
        $this->startedAt = $startedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOrganizationId(): Uuid
    {
        return $this->organizationId;
    }

    public function setOrganizationId(Uuid $organizationId): void
    {
        $this->organizationId = $organizationId;
    }

    public function getEmailAccountId(): Uuid
    {
        return $this->emailAccountId;
    }

    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getStatus(): SyncRunStatus
    {
        return $this->status;
    }

    public function getMessagesSeen(): int
    {
        return $this->messagesSeen;
    }

    public function getMessagesProcessed(): int
    {
        return $this->messagesProcessed;
    }

    public function getMessagesSkipped(): int
    {
        return $this->messagesSkipped;
    }

    public function getMessagesBackfilled(): int
    {
        return $this->messagesBackfilled;
    }

    public function getDiscoveriesCreated(): int
    {
        return $this->discoveriesCreated;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function countSeen(int $count = 1): void
    {
        $this->messagesSeen += $count;
    }

    public function countProcessed(int $count = 1): void
    {
        $this->messagesProcessed += $count;
    }

    public function countSkipped(int $count = 1): void
    {
        $this->messagesSkipped += $count;
    }

    public function countBackfilled(int $count = 1): void
    {
        $this->messagesBackfilled += $count;
    }

    public function countDiscovery(int $count = 1): void
    {
        $this->discoveriesCreated += $count;
    }

    public function complete(DateTimeImmutable $at): void
    {
        $this->status = SyncRunStatus::COMPLETED;
        $this->finishedAt = $at;
    }

    public function fail(DateTimeImmutable $at, string $error): void
    {
        $this->status = SyncRunStatus::FAILED;
        $this->finishedAt = $at;
        $this->error = mb_substr($error, 0, 2000);
    }
}
