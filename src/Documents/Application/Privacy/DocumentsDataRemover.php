<?php

declare(strict_types=1);

namespace App\Documents\Application\Privacy;

use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Documents\Domain\Repository\InvoiceRepositoryInterface;
use App\Documents\Domain\Service\DocumentStorageInterface;
use App\Shared\Application\Privacy\OrganizationDataRemoverInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Borra los documentos y las facturas de una organización.
 *
 * Los binarios se borran **antes** que las filas: si el borrado de la fila
 * funcionara pero el del fichero fallara, quedaría un archivo huérfano en
 * disco que nadie sabría identificar. Al revés es imposible: la fila es la
 * que dice dónde estaba el fichero.
 *
 * Un binario que ya no existe no es un error: se sigue adelante. Un borrado a
 * medias es peor que un borrado incompleto, porque el usuario cree que ya no
 * está.
 */
final readonly class DocumentsDataRemover implements OrganizationDataRemoverInterface
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
        private InvoiceRepositoryInterface $invoices,
        private DocumentStorageInterface $storage,
    ) {
    }

    public function module(): string
    {
        return 'documents';
    }

    /**
     * Los documentos apuntan a servicios: se van antes que ellos.
     */
    public function priority(): int
    {
        return 40;
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        $documents = $this->documents->findAllForOrganization($organizationId);

        foreach ($documents as $document) {
            try {
                $this->storage->delete($document->getStorageKey());
            } catch (Throwable) {
                // El fichero ya no está o no se puede tocar: la fila se borra
                // igualmente, para no dejar un documento «vivo» en el panel.
            }
        }

        $invoicesRemoved = $this->invoices->removeForOrganization($organizationId);
        $documentsRemoved = $this->documents->removeForOrganization($organizationId);

        return $documentsRemoved + $invoicesRemoved;
    }
}
