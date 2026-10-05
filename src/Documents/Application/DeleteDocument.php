<?php

declare(strict_types=1);

namespace App\Documents\Application;

use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Borra un documento.
 *
 * El borrado es lógico: la fila se marca y deja de aparecer, pero el binario
 * sigue en el almacenamiento hasta que la purga por retención lo elimine de
 * verdad (SECURITY.md §6). Borrar el fichero al instante haría imposible
 * deshacer un error del usuario.
 */
final readonly class DeleteDocument
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
        private AuditLoggerInterface $auditLogger,
        private Clock $clock,
    ) {
    }

    public function __invoke(Uuid $documentId, ?Uuid $actorUserId = null): void
    {
        $document = $this->documents->find($documentId);

        if (null === $document) {
            throw new InvalidArgumentException('El documento no existe.');
        }

        if ($document->isDeleted()) {
            return;
        }

        $document->delete($this->clock->now());

        $this->documents->save($document);

        $this->auditLogger->log(
            action: AuditAction::DOCUMENT_DELETED,
            targetType: 'document',
            targetId: $document->getId()->toRfc4122(),
            metadata: ['filename' => $document->getOriginalFilename()],
            actorUserId: $actorUserId,
        );
    }
}
