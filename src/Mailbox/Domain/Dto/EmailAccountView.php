<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Dto;

use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Enum\EmailAccountStatus;
use App\Mailbox\Domain\Enum\SyncRunStatus;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Vista de lectura de una cuenta de correo (ARCHITECTURE.md §3).
 *
 * ## Lo que no aparece aquí
 *
 * Ni las credenciales cifradas ni el usuario IMAP: una vista que se expose
 * por la API no puede llevar material sensible dentro, ni siquiera cifrado.
 * Lo que sí aparece es si la cuenta **tiene** credenciales, que es lo que un
 * integrador necesita para saber si puede sincronizarla.
 */
final readonly class EmailAccountView
{
    public function __construct(
        public Uuid $id,
        public string $provider,
        public string $providerLabel,
        public string $emailAddress,
        public ?string $displayName,
        public string $status,
        public string $statusLabel,
        public ?string $imapHost,
        public ?int $imapPort,
        public ?string $imapEncryption,
        public string $imapFolder,
        public bool $hasCredentials,
        public bool $configured,
        public bool $forwardingEnabled,
        public ?string $forwardingAddress,
        public ?DateTimeImmutable $lastSyncAt,
        public ?string $lastSyncStatus,
        public ?string $lastSyncError,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public static function fromAccount(EmailAccount $account): self
    {
        $lastSyncStatus = $account->getLastSyncStatus();

        return new self(
            id: $account->getId(),
            provider: $account->getProvider()->value,
            providerLabel: $account->getProvider()->label(),
            emailAddress: $account->getEmailAddress(),
            displayName: $account->getDisplayName(),
            status: $account->getStatus()->value,
            statusLabel: $account->getStatus()->label(),
            imapHost: $account->getImapHost(),
            imapPort: $account->getImapPort(),
            imapEncryption: $account->getImapEncryption()?->value,
            imapFolder: $account->getImapFolder(),
            hasCredentials: $account->hasCredentials(),
            configured: $account->isConfigured(),
            forwardingEnabled: $account->isForwardingEnabled(),
            forwardingAddress: $account->getForwardingAddress(),
            lastSyncAt: $account->getLastSyncAt(),
            lastSyncStatus: $lastSyncStatus instanceof SyncRunStatus ? $lastSyncStatus->value : null,
            lastSyncError: $account->getLastSyncError(),
            createdAt: $account->getCreatedAt(),
            updatedAt: $account->getUpdatedAt(),
        );
    }

    public function statusEnum(): EmailAccountStatus
    {
        return EmailAccountStatus::from($this->status);
    }

    public function providerEnum(): EmailAccountProvider
    {
        return EmailAccountProvider::from($this->provider);
    }
}
