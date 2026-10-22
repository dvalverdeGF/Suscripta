<?php

declare(strict_types=1);

namespace App\Documents\Domain\Dto;

use App\Documents\Domain\Entity\Document;
use App\Documents\Domain\Enum\DocumentSource;
use App\Documents\Domain\Enum\DocumentType;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Vista de lectura de un documento (ARCHITECTURE.md §3).
 *
 * No expone la clave de almacenamiento (`storageKey`): quien lista documentos
 * necesita saber qué hay, no dónde está el binario. La descarga se hace
 * siempre a través del módulo `Documents`, que es quien comprueba permisos y
 * resuelve la ruta.
 */
final readonly class DocumentView
{
    public function __construct(
        public Uuid $id,
        public string $type,
        public string $typeLabel,
        public string $source,
        public string $originalFilename,
        public string $mimeType,
        public int $sizeBytes,
        public ?Uuid $serviceId,
        public ?Uuid $invoiceId,
        public ?Uuid $emailMessageId,
        public string $checksumSha256,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $deletedAt,
        public bool $deleted,
    ) {
    }

    public static function fromDocument(Document $document): self
    {
        return new self(
            id: $document->getId(),
            type: $document->getType()->value,
            typeLabel: $document->getType()->label(),
            source: $document->getSource()->value,
            originalFilename: $document->getOriginalFilename(),
            mimeType: $document->getMimeType(),
            sizeBytes: $document->getSizeBytes(),
            serviceId: $document->getServiceId(),
            invoiceId: $document->getInvoiceId(),
            emailMessageId: $document->getEmailMessageId(),
            checksumSha256: $document->getChecksumSha256(),
            createdAt: $document->getCreatedAt(),
            deletedAt: $document->getDeletedAt(),
            deleted: $document->isDeleted(),
        );
    }

    public function typeEnum(): DocumentType
    {
        return DocumentType::from($this->type);
    }

    public function sourceEnum(): DocumentSource
    {
        return DocumentSource::from($this->source);
    }
}
