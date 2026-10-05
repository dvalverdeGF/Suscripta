<?php

declare(strict_types=1);

namespace App\Documents\Application;

use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Documents\Domain\Service\DocumentStorageInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Lee el binario de un documento.
 *
 * Es el único camino por el que un fichero sale del sistema. Existe para que
 * cada lectura quede auditada y para que el aislamiento entre organizaciones
 * lo garantice el filtro de tenencia, no una URL difícil de adivinar
 * (SECURITY.md §1 y §5).
 */
final readonly class DownloadDocument
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
        private DocumentStorageInterface $storage,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(Uuid $documentId, ?Uuid $actorUserId = null): DocumentContents
    {
        $document = $this->documents->find($documentId);

        if (null === $document || $document->isDeleted()) {
            throw new InvalidArgumentException('El documento no existe.');
        }

        $contents = $this->storage->read($document->getStorageKey());

        $this->auditLogger->log(
            action: AuditAction::DOCUMENT_DOWNLOADED,
            targetType: 'document',
            targetId: $document->getId()->toRfc4122(),
            metadata: ['filename' => $document->getOriginalFilename()],
            actorUserId: $actorUserId,
        );

        return new DocumentContents($document, $contents);
    }
}
