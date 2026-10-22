<?php

declare(strict_types=1);

namespace App\Documents\Application\Privacy;

use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Shared\Application\Privacy\OrganizationDataProviderInterface;

use function count;

use Symfony\Component\Uid\Uuid;

/**
 * Sección «documents» de la exportación: los documentos almacenados.
 *
 * Se exportan los **metadatos**, no los bytes: los ficheros son grandes y
 *se pueden volver a descargar desde el panel, y lo que el usuario necesita para sus propios
 * cálculos son los datos de la factura, no el PDF.
 *
 * El contenido se puede descargar aparte desde el panel, documento a
 * documento, con su propia auditoría.
 */
final readonly class DocumentsDataProvider implements OrganizationDataProviderInterface
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
    ) {
    }

    public function section(): string
    {
        return 'documents';
    }

    public function export(Uuid $organizationId): array
    {
        $documents = $this->documents->findAllForOrganization($organizationId);

        $rows = [];

        foreach ($documents as $document) {
            $rows[] = [
                'id' => $document->getId()->toRfc4122(),
                'filename' => $document->getOriginalFilename(),
                'type' => $document->getType()->value,
                'source' => $document->getSource()->value,
                'mimeType' => $document->getMimeType(),
                'sizeBytes' => $document->getSizeBytes(),
                'checksum' => $document->getChecksumSha256(),
                'serviceId' => $document->getServiceId()?->toRfc4122(),
                'invoiceId' => $document->getInvoiceId()?->toRfc4122(),
                'emailMessageId' => $document->getEmailMessageId()?->toRfc4122(),
                'createdAt' => $document->getCreatedAt()->format('c'),
                'deletedAt' => $document->getDeletedAt()?->format('c'),
            ];
        }

        return [
            'meta' => ['count' => count($rows)],
            'rows' => $rows,
        ];
    }
}
