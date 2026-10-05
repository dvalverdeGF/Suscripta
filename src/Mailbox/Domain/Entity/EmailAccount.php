<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Entity;

use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Enum\EmailAccountStatus;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Enum\SyncRunStatus;
use App\Shared\Domain\Contract\TenantAwareInterface;
use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use const FILTER_VALIDATE_EMAIL;

use function in_array;
use function sprintf;
use function str_ends_with;
use function str_starts_with;

use Symfony\Component\Uid\Uuid;

/**
 * Buzón de correo conectado por la organización (ARCHITECTURE.md §4.5).
 *
 * El correo es una **fuente de información**, no el producto: aquí solo se
 * guarda lo necesario para volver a leerlo. Nunca se almacena el cuerpo de los
 * mensajes (D-10) y las credenciales nunca se guardan en claro (SECURITY.md §3).
 *
 * La cuenta pertenece a la **organización**, no al usuario: una organización
 * puede conectar varias cuentas y todas alimentan el mismo inventario de
 * servicios (D-27).
 */
#[ORM\Entity]
#[ORM\Table(name: 'email_account')]
#[ORM\Index(name: 'idx_email_account_organization', columns: ['organization_id'])]
#[ORM\UniqueConstraint(name: 'uniq_email_account_org_address', columns: ['organization_id', 'email_address'])]
class EmailAccount implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: EmailAccountProvider::class)]
    private EmailAccountProvider $provider;

    #[ORM\Column(name: 'email_address', type: Types::STRING, length: 180)]
    private string $emailAddress;

    #[ORM\Column(name: 'display_name', type: Types::STRING, length: 120, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: EmailAccountStatus::class)]
    private EmailAccountStatus $status = EmailAccountStatus::PENDING;

    /**
     * Contraseña o token de aplicación, cifrado con libsodium.
     *
     * El valor es opaco para el dominio: solo lo abre `CredentialCipherInterface`
     * en el momento de abrir la conexión (SECURITY.md §3).
     */
    #[ORM\Column(name: 'credentials_encrypted', type: Types::TEXT, nullable: true)]
    private ?string $credentialsEncrypted = null;

    #[ORM\Column(name: 'imap_host', type: Types::STRING, length: 180, nullable: true)]
    private ?string $imapHost = null;

    #[ORM\Column(name: 'imap_port', type: Types::SMALLINT, nullable: true)]
    private ?int $imapPort = null;

    #[ORM\Column(name: 'imap_encryption', type: Types::STRING, length: 20, enumType: ImapEncryption::class, nullable: true)]
    private ?ImapEncryption $imapEncryption = null;

    #[ORM\Column(name: 'imap_username', type: Types::STRING, length: 180, nullable: true)]
    private ?string $imapUsername = null;

    /** Carpeta que se sincroniza. `INBOX` por defecto; el usuario puede cambiarla. */
    #[ORM\Column(name: 'imap_folder', type: Types::STRING, length: 120)]
    private string $imapFolder = 'INBOX';

    /**
     * Dirección dedicada de ingesta por reenvío (D-21).
     *
     * Es única y no adivinable: quien la conozca puede inyectar correo, así que
     * se trata como un secreto y se puede rotar desde la interfaz.
     */
    #[ORM\Column(name: 'forwarding_address', type: Types::STRING, length: 180, nullable: true)]
    private ?string $forwardingAddress = null;

    /**
     * Remitentes autorizados a reenviar a esa dirección.
     *
     * Sin esta lista, cualquiera que descubriera la dirección podría meter
     * facturas falsas en el inventario (SECURITY.md §2.4).
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'forwarding_senders', type: Types::JSON)]
    private array $forwardingSenders = [];

    #[ORM\Column(name: 'forwarding_enabled', type: Types::BOOLEAN)]
    private bool $forwardingEnabled = false;

    #[ORM\Column(name: 'last_sync_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastSyncAt = null;

    #[ORM\Column(name: 'last_sync_status', type: Types::STRING, length: 20, enumType: SyncRunStatus::class, nullable: true)]
    private ?SyncRunStatus $lastSyncStatus = null;

    #[ORM\Column(name: 'last_sync_error', type: Types::TEXT, nullable: true)]
    private ?string $lastSyncError = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /**
     * Borrado lógico. La eliminación real de la cuenta y de todo lo derivado es
     * una operación explícita y auditada (SECURITY.md §6), no un efecto
     * colateral de pulsar un botón.
     */
    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    public function __construct(
        Uuid $organizationId,
        string $emailAddress,
        EmailAccountProvider $provider = EmailAccountProvider::IMAP,
    ) {
        $emailAddress = mb_strtolower(trim($emailAddress));

        if (false === filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(sprintf('"%s" no es una dirección de correo válida.', $emailAddress));
        }

        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->emailAddress = $emailAddress;
        $this->provider = $provider;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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

    public function getProvider(): EmailAccountProvider
    {
        return $this->provider;
    }

    public function getEmailAddress(): string
    {
        return $this->emailAddress;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): void
    {
        $this->displayName = null === $displayName ? null : (trim($displayName) ?: null);
        $this->touch();
    }

    public function getStatus(): EmailAccountStatus
    {
        return $this->status;
    }

    public function getCredentialsEncrypted(): ?string
    {
        return $this->credentialsEncrypted;
    }

    public function setCredentialsEncrypted(?string $credentialsEncrypted): void
    {
        $this->credentialsEncrypted = $credentialsEncrypted;
        $this->touch();
    }

    public function hasCredentials(): bool
    {
        return null !== $this->credentialsEncrypted && '' !== $this->credentialsEncrypted;
    }

    public function getImapHost(): ?string
    {
        return $this->imapHost;
    }

    public function getImapPort(): ?int
    {
        return $this->imapPort;
    }

    public function getImapEncryption(): ?ImapEncryption
    {
        return $this->imapEncryption;
    }

    public function getImapUsername(): ?string
    {
        return $this->imapUsername;
    }

    public function getImapFolder(): string
    {
        return $this->imapFolder;
    }

    public function setImapFolder(string $imapFolder): void
    {
        $imapFolder = trim($imapFolder);

        if ('' === $imapFolder) {
            throw new InvalidArgumentException('La carpeta de correo no puede estar vacía.');
        }

        $this->imapFolder = $imapFolder;
        $this->touch();
    }

    /**
     * Configura el servidor IMAP. El puerto se deduce del cifrado si no se
     * indica, porque el 993 y el 143 son la respuesta correcta en la inmensa
     * mayoría de los casos y pedírselo al usuario solo añade fricción.
     */
    public function configureImap(
        string $host,
        ImapEncryption $encryption,
        ?int $port = null,
        ?string $username = null,
    ): void {
        $host = trim($host);

        if ('' === $host) {
            throw new InvalidArgumentException('El servidor IMAP no puede estar vacío.');
        }

        $port ??= $encryption->defaultPort();

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('El puerto IMAP debe estar entre 1 y 65535.');
        }

        $this->imapHost = $host;
        $this->imapPort = $port;
        $this->imapEncryption = $encryption;
        $this->imapUsername = null === $username ? $this->emailAddress : (trim($username) ?: $this->emailAddress);
        $this->touch();
    }

    public function isConfigured(): bool
    {
        return null !== $this->imapHost
            && null !== $this->imapPort
            && null !== $this->imapEncryption
            && null !== $this->imapUsername
            && $this->hasCredentials();
    }

    public function markActive(): void
    {
        $this->status = EmailAccountStatus::ACTIVE;
        $this->lastSyncError = null;
        $this->touch();
    }

    public function markError(string $error): void
    {
        $this->status = EmailAccountStatus::ERROR;
        $this->lastSyncError = mb_substr($error, 0, 2000);
        $this->touch();
    }

    public function disable(): void
    {
        $this->status = EmailAccountStatus::DISABLED;
        $this->touch();
    }

    public function recordSync(SyncRunStatus $status, DateTimeImmutable $at, ?string $error = null): void
    {
        $this->lastSyncAt = $at;
        $this->lastSyncStatus = $status;
        $this->lastSyncError = null === $error ? null : mb_substr($error, 0, 2000);
        $this->touch();
    }

    /**
     * Activa la ingesta por reenvío con una dirección dedicada.
     *
     * @param list<string> $senders remitentes autorizados
     */
    public function enableForwarding(string $address, array $senders): void
    {
        $address = mb_strtolower(trim($address));

        if (false === filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(sprintf('"%s" no es una dirección de ingesta válida.', $address));
        }

        $this->forwardingAddress = $address;
        $this->forwardingSenders = $this->normalizeSenders($senders);
        $this->forwardingEnabled = true;
        $this->touch();
    }

    /**
     * Cambia la dirección de ingesta conservando los remitentes autorizados.
     *
     * Rotar la dirección es la respuesta a una fuga: si alguien la ha
     * descubierto, se cambia y la antigua deja de servir.
     */
    public function rotateForwardingAddress(string $address): void
    {
        if (null === $this->forwardingAddress) {
            throw new InvalidArgumentException('La cuenta no tiene activada la ingesta por reenvío.');
        }

        $this->enableForwarding($address, $this->forwardingSenders);
    }

    public function disableForwarding(): void
    {
        $this->forwardingEnabled = false;
        $this->touch();
    }

    /**
     * @param list<string> $senders
     */
    public function setForwardingSenders(array $senders): void
    {
        $this->forwardingSenders = $this->normalizeSenders($senders);
        $this->touch();
    }

    public function isForwardingEnabled(): bool
    {
        return $this->forwardingEnabled && null !== $this->forwardingAddress;
    }

    public function getForwardingAddress(): ?string
    {
        return $this->forwardingAddress;
    }

    /** @return list<string> */
    public function getForwardingSenders(): array
    {
        return $this->forwardingSenders;
    }

    /**
     * ¿Está este remitente autorizado a reenviar a esta cuenta?
     *
     * Se acepta la dirección completa o su dominio (`@ejemplo.com`), porque un
     * autónomo suele reenviar desde su propia dirección y no siempre desde la
     * misma.
     */
    public function isSenderAuthorized(string $sender): bool
    {
        $sender = mb_strtolower(trim($sender));

        if ('' === $sender) {
            return false;
        }

        foreach ($this->forwardingSenders as $allowed) {
            if ($allowed === $sender) {
                return true;
            }

            if (str_starts_with($allowed, '@') && str_ends_with($sender, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $senders
     *
     * @return list<string>
     */
    private function normalizeSenders(array $senders): array
    {
        $normalized = [];

        foreach ($senders as $sender) {
            $sender = mb_strtolower(trim($sender));

            if ('' === $sender) {
                continue;
            }

            if (!str_starts_with($sender, '@') && false === filter_var($sender, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException(sprintf('"%s" no es una dirección ni un dominio válido.', $sender));
            }

            if (!in_array($sender, $normalized, true)) {
                $normalized[] = $sender;
            }
        }

        return $normalized;
    }

    public function getLastSyncAt(): ?DateTimeImmutable
    {
        return $this->lastSyncAt;
    }

    public function getLastSyncStatus(): ?SyncRunStatus
    {
        return $this->lastSyncStatus;
    }

    public function getLastSyncError(): ?string
    {
        return $this->lastSyncError;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function markDeleted(DateTimeImmutable $at): void
    {
        $this->deletedAt = $at;
        $this->status = EmailAccountStatus::DISABLED;
        $this->credentialsEncrypted = null;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
