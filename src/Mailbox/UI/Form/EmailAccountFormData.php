<?php

declare(strict_types=1);

namespace App\Mailbox\UI\Form;

use App\Mailbox\Application\Dto\EmailAccountInput;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\ValueObject\EmailAccountCredentials;

/**
 * Puente entre el formulario de conexión de buzón y `EmailAccountInput`.
 *
 * La contraseña **nunca** se rellena desde la entidad: al editar un buzón ya
 * conectado el campo aparece vacío y solo se envía si el usuario quiere
 * cambiarla (SECURITY.md §3). Vacío significa «conserva las credenciales que ya
 * estaban guardadas», que es lo que espera `UpdateEmailAccount`.
 */
final class EmailAccountFormData
{
    public ?string $emailAddress = null;
    public ?string $password = null;
    public ?string $imapHost = null;
    public ?ImapEncryption $imapEncryption = ImapEncryption::SSL;
    public ?int $imapPort = null;
    public ?string $imapUsername = null;
    public ?string $imapFolder = 'INBOX';
    public ?string $displayName = null;
    public ?EmailAccountProvider $provider = EmailAccountProvider::IMAP;

    public static function fromAccount(EmailAccount $account): self
    {
        $data = new self();
        $data->emailAddress = $account->getEmailAddress();
        $data->imapHost = $account->getImapHost();
        $data->imapEncryption = $account->getImapEncryption() ?? ImapEncryption::SSL;
        $data->imapPort = $account->getImapPort();
        $data->imapUsername = $account->getImapUsername();
        $data->imapFolder = $account->getImapFolder();
        $data->displayName = $account->getDisplayName();
        $data->provider = $account->getProvider();

        return $data;
    }

    public function toInput(): EmailAccountInput
    {
        return new EmailAccountInput(
            emailAddress: $this->emailAddress ?? '',
            credentials: $this->credentials(),
            imapHost: $this->imapHost ?? '',
            imapEncryption: $this->imapEncryption ?? ImapEncryption::SSL,
            imapPort: $this->imapPort,
            imapUsername: $this->imapUsername,
            imapFolder: $this->imapFolder ?? 'INBOX',
            displayName: $this->displayName,
            provider: $this->provider ?? EmailAccountProvider::IMAP,
        );
    }

    private function credentials(): ?EmailAccountCredentials
    {
        if (null === $this->password || '' === $this->password) {
            return null;
        }

        return EmailAccountCredentials::fromPlaintext($this->password);
    }
}
