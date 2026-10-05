<?php

declare(strict_types=1);

namespace App\Documents\Domain\Entity;

use App\Documents\Domain\Enum\DocumentSource;
use App\Documents\Domain\Enum\DocumentType;
use App\Shared\Domain\Contract\TenantAwareInterface;
use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function mb_substr;
use function sprintf;
use function strlen;

use Symfony\Component\Uid\Uuid;

/**
 * Un fichero que conservamos porque explica un gasto (ARCHITECTURE.md §4.4).
 *
 * El documento **no guarda el contenido**: guarda dónde está (`storageDriver` +
 * `storageKey`) y su huella (`checksumSha256`). Así el binario puede vivir en
 * disco, en S3 o donde haga falta sin tocar el dominio, y la huella sirve para
 * deduplicar: la misma factura que llega por dos buzones es un solo documento.
 *
 * El borrado es **lógico** (`deletedAt`). Un documento es la prueba de un cobro;
 * si el usuario lo borra por error, poder recuperarlo durante un tiempo es más
 * valioso que ahorrar unas filas.
 */
#[ORM\Entity]
#[ORM\Table(name: 'document')]
#[ORM\Index(name: 'idx_document_organization_created', columns: ['organization_id', 'created_at'])]
#[ORM\Index(name: 'idx_document_service', columns: ['service_id'])]
#[ORM\Index(name: 'idx_document_invoice', columns: ['invoice_id'])]
#[ORM\Index(name: 'idx_document_email_message', columns: ['email_message_id'])]
#[ORM\UniqueConstraint(name: 'uniq_document_checksum', columns: ['organization_id', 'checksum_sha256'])]
class Document implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    #[ORM\Column(name: 'service_id', type: 'uuid', nullable: true)]
    private ?Uuid $serviceId = null;

    #[ORM\Column(name: 'invoice_id', type: 'uuid', nullable: true)]
    private ?Uuid $invoiceId = null;

    #[ORM\Column(name: 'email_message_id', type: 'uuid', nullable: true)]
    private ?Uuid $emailMessageId = null;

    #[ORM\Column(name: 'original_filename', type: Types::STRING, length: 255)]
    private string $originalFilename;

    #[ORM\Column(name: 'storage_driver', type: Types::STRING, length: 40)]
    private string $storageDriver;

    #[ORM\Column(name: 'storage_key', type: Types::STRING, length: 255)]
    private string $storageKey;

    #[ORM\Column(name: 'mime_type', type: Types::STRING, length: 120)]
    private string $mimeType;

    #[ORM\Column(name: 'size_bytes', type: Types::INTEGER)]
    private int $sizeBytes;

    #[ORM\Column(name: 'checksum_sha256', type: Types::STRING, length: 64)]
    private string $checksumSha256;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: DocumentType::class)]
    private DocumentType $type;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: DocumentSource::class)]
    private DocumentSource $source;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    public function __construct(
        Uuid $organizationId,
        string $originalFilename,
        string $storageDriver,
        string $storageKey,
        string $mimeType,
        int $sizeBytes,
        string $checksumSha256,
        DocumentType $type,
        DocumentSource $source,
        ?DateTimeImmutable $createdAt = null,
    ) {
        if ('' === $originalFilename) {
            throw new InvalidArgumentException('El documento necesita un nombre de fichero.');
        }

        if ('' === $storageKey) {
            throw new InvalidArgumentException('El documento necesita una clave de almacenamiento.');
        }

        if ($sizeBytes < 0) {
            throw new InvalidArgumentException('El tamaño del documento no puede ser negativo.');
        }

        if (64 !== strlen($checksumSha256)) {
            throw new InvalidArgumentException('La huella del documento debe ser un SHA-256 en hexadecimal.');
        }

        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->originalFilename = mb_substr($originalFilename, 0, 255);
        $this->storageDriver = $storageDriver;
        $this->storageKey = $storageKey;
        $this->mimeType = $mimeType;
        $this->sizeBytes = $sizeBytes;
        $this->checksumSha256 = $checksumSha256;
        $this->type = $type;
        $this->source = $source;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
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

    public function getServiceId(): ?Uuid
    {
        return $this->serviceId;
    }

    public function getInvoiceId(): ?Uuid
    {
        return $this->invoiceId;
    }

    public function getEmailMessageId(): ?Uuid
    {
        return $this->emailMessageId;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function getStorageDriver(): string
    {
        return $this->storageDriver;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getChecksumSha256(): string
    {
        return $this->checksumSha256;
    }

    public function getType(): DocumentType
    {
        return $this->type;
    }

    public function setType(DocumentType $type): void
    {
        $this->type = $type;
    }

    public function getSource(): DocumentSource
    {
        return $this->source;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    public function attachToService(?Uuid $serviceId): void
    {
        $this->serviceId = $serviceId;
    }

    public function attachToInvoice(?Uuid $invoiceId): void
    {
        $this->invoiceId = $invoiceId;
    }

    public function linkToEmailMessage(?Uuid $emailMessageId): void
    {
        $this->emailMessageId = $emailMessageId;
    }

    /**
     * Borrado lógico. Es idempotente: borrar dos veces no cambia la fecha
     * original, porque lo que importa es cuándo se retiró de verdad.
     */
    public function delete(DateTimeImmutable $at): void
    {
        $this->deletedAt ??= $at;
    }

    public function restore(): void
    {
        $this->deletedAt = null;
    }

    /**
     * Nombre legible para el usuario, sin la ruta ni caracteres de control que
     * algunos clientes de correo meten en el nombre del adjunto.
     */
    public function displayName(): string
    {
        return sprintf('%s (%s)', $this->originalFilename, $this->humanSize());
    }

    public function humanSize(): string
    {
        if ($this->sizeBytes < 1024) {
            return sprintf('%d B', $this->sizeBytes);
        }

        if ($this->sizeBytes < 1024 * 1024) {
            return sprintf('%.1f KB', $this->sizeBytes / 1024);
        }

        return sprintf('%.1f MB', $this->sizeBytes / (1024 * 1024));
    }
}
