<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Privacy;

use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Shared\Application\Privacy\OrganizationDataProviderInterface;

use function count;

use Symfony\Component\Uid\Uuid;

/**
 * Sección «mailbox» de la exportación: los buzones conectados.
 *
 * **No se exportan las credenciales.** Ni la contraseña en claro, ni el
 * texto cifrado, ni el usuario IMAP: son secretos, no datos personales, y un
 * archivo de exportación se manda por correo (SECURITY.md §9).
 *
 * Lo que sí se exporta es la configuración del buzón (servidor, puerto,
 * carpeta) y el estado de la conexión, que es lo que el usuario necesita
 * para saber qué está conectado.
 */
final readonly class MailboxDataProvider implements OrganizationDataProviderInterface
{
    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
    ) {
    }

    public function section(): string
    {
        return 'mailbox';
    }

    public function export(Uuid $organizationId): array
    {
        $accounts = $this->accounts->findAllForOrganization($organizationId);

        $rows = [];

        foreach ($accounts as $account) {
            $rows[] = [
                'id' => $account->getId()->toRfc4122(),
                'address' => $account->getEmailAddress(),
                'displayName' => $account->getDisplayName(),
                'provider' => $account->getProvider()->value,
                'status' => $account->getStatus()->value,
                'hasCredentials' => $account->hasCredentials(),
                'imapHost' => $account->getImapHost(),
                'imapPort' => $account->getImapPort(),
                'imapEncryption' => $account->getImapEncryption()?->value,
                'imapFolder' => $account->getImapFolder(),
                'forwardingEnabled' => $account->isForwardingEnabled(),
                'forwardingAddress' => $account->getForwardingAddress(),
                'lastSyncAt' => $account->getLastSyncAt()?->format('c'),
                'lastSyncStatus' => $account->getLastSyncStatus()?->value,
                'createdAt' => $account->getCreatedAt()->format('c'),
                'deletedAt' => $account->getDeletedAt()?->format('c'),
            ];
        }

        return [
            'meta' => ['count' => count($rows)],
            'rows' => $rows,
        ];
    }
}
