<?php

declare(strict_types=1);

namespace App\Documents\Application;

use App\Documents\Application\Dto\DocumentUpload;
use App\Documents\Domain\Entity\Document;
use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Documents\Domain\Service\DocumentStorageInterface;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;

use function bin2hex;
use function hash;
use function in_array;
use function intdiv;
use function preg_match;
use function sprintf;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;

use Symfony\Component\Uid\Uuid;

/**
 * Guarda un documento y lo deja disponible para el dominio.
 *
 * Dos reglas que no son negociables:
 *
 * 1. **Deduplicación por huella.** El mismo fichero no se guarda dos veces en
 *    la misma organización. Si ya existe, se devuelve el que hay en lugar de
 *    crear un duplicado: la misma factura que llega por dos buzones es una
 *    sola factura (D-27).
 * 2. **Nada se sirve por URL directa.** El binario se escribe en el
 *    almacenamiento y solo se lee a través de un caso de uso que comprueba
 *    permisos (SECURITY.md §1).
 */
final readonly class UploadDocument
{
    /**
     * Tipos aceptados. La lista es blanca, no negra: un tipo desconocido se
     * rechaza en lugar de aceptarse "por si acaso".
     */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/tiff',
        'text/plain',
        'text/csv',
        'application/xml',
        'text/xml',
        'application/zip',
    ];

    private const MAX_SIZE_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private DocumentRepositoryInterface $documents,
        private DocumentStorageInterface $storage,
        private ServiceRepositoryInterface $services,
        private TenantContext $tenantContext,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    /**
     * @return array{document: Document, created: bool}
     */
    public function __invoke(DocumentUpload $upload, ?Uuid $actorUserId = null): array
    {
        $organizationId = $this->tenantContext->requireOrganizationId();

        $this->assertAcceptable($upload);

        $checksum = bin2hex(hash('sha256', $upload->contents, true));

        $existing = $this->documents->findByChecksum($checksum);

        if (null !== $existing) {
            // Un documento borrado no se resucita solo: el usuario lo quitó a
            // propósito. Se devuelve para que quien llama decida qué hacer.
            return ['document' => $existing, 'created' => false];
        }

        $key = $this->buildStorageKey($organizationId, $checksum, $upload->originalFilename);

        $this->storage->write($key, $upload->contents);

        $document = new Document(
            organizationId: $organizationId,
            originalFilename: $upload->originalFilename,
            storageDriver: $this->storage->driver(),
            storageKey: $key,
            mimeType: strtolower($upload->mimeType),
            sizeBytes: strlen($upload->contents),
            checksumSha256: $checksum,
            type: $upload->type,
            source: $upload->source,
        );

        $document->attachToService($upload->serviceId);
        $document->linkToEmailMessage($upload->emailMessageId);

        $this->documents->save($document);

        if (null !== $upload->serviceId) {
            $this->recordServiceEvent($upload->serviceId, $document, $actorUserId);
        }

        $this->auditLogger->log(
            action: AuditAction::DOCUMENT_UPLOADED,
            targetType: 'document',
            targetId: $document->getId()->toRfc4122(),
            metadata: [
                'filename' => $document->getOriginalFilename(),
                'mimeType' => $document->getMimeType(),
                'sizeBytes' => $document->getSizeBytes(),
                'source' => $document->getSource()->value,
            ],
            actorUserId: $actorUserId,
        );

        return ['document' => $document, 'created' => true];
    }

    private function assertAcceptable(DocumentUpload $upload): void
    {
        if ('' === $upload->contents) {
            throw new InvalidArgumentException('El documento está vacío.');
        }

        if ('' === $upload->originalFilename) {
            throw new InvalidArgumentException('El documento necesita un nombre de fichero.');
        }

        if (strlen($upload->contents) > self::MAX_SIZE_BYTES) {
            throw new InvalidArgumentException(sprintf('El documento supera el tamaño máximo de %d MB.', intdiv(self::MAX_SIZE_BYTES, 1024 * 1024)));
        }

        if (!in_array(strtolower($upload->mimeType), self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException(sprintf('No aceptamos ficheros de tipo "%s".', $upload->mimeType));
        }
    }

    /**
     * La clave se reparte en dos niveles por los dos primeros bytes de la
     * huella. Un directorio con cien mil ficheros es lento de listar y de
     * copiar; repartirlos mantiene el almacenamiento manejable.
     */
    private function buildStorageKey(Uuid $organizationId, string $checksum, string $filename): string
    {
        $extension = $this->extensionOf($filename);

        return sprintf(
            '%s/%s/%s/%s%s',
            $organizationId->toRfc4122(),
            substr($checksum, 0, 2),
            substr($checksum, 2, 2),
            $checksum,
            $extension,
        );
    }

    private function extensionOf(string $filename): string
    {
        $position = strrpos($filename, '.');

        if (false === $position) {
            return '';
        }

        $extension = strtolower(substr($filename, $position));

        // Solo extensiones cortas y alfanuméricas: el nombre viene de fuera y
        // no queremos que acabe formando parte de una ruta.
        if (strlen($extension) > 10 || 1 !== preg_match('/^\.[a-z0-9]+$/', $extension)) {
            return '';
        }

        return $extension;
    }

    private function recordServiceEvent(Uuid $serviceId, Document $document, ?Uuid $actorUserId): void
    {
        $service = $this->services->find($serviceId);

        if (null === $service) {
            return;
        }

        $service->recordEvent(ServiceEventType::DOCUMENT_ADDED, $actorUserId, [
            'documentId' => $document->getId()->toRfc4122(),
            'filename' => $document->getOriginalFilename(),
        ]);

        $this->services->save($service);
    }
}
