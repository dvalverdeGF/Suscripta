<?php

declare(strict_types=1);

namespace App\Documents\Application\Privacy;

use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Documents\Domain\Service\DocumentStorageInterface;
use App\Shared\Application\Privacy\PrivacySettings;
use App\Shared\Application\Privacy\RetentionPurgerInterface;
use DateTimeImmutable;
use Throwable;

/**
 * Purga los documentos que el usuario borró hace tiempo.
 *
 * El borrado de un documento es **lógico** (se marca la fila, el binario
 * sigue ahí por si el usuario se arrepiente). Esta purga es el paso
 * definitive: desaparece la fila y el fichero.
 *
 * El binario se borra primero. Si el borrado del fichero fallara y el de la
 * fila no, quedaría un fichero huérfano en el disco sin forma de encontrarlo.
 * Al revés, la fila se queda y el fichero ya no: el documento sigue visible
 * como «borrado», que es un estado coherente.
 */
final readonly class DocumentsPurger implements RetentionPurgerInterface
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
        private DocumentStorageInterface $storage,
        private PrivacySettings $settings,
    ) {
    }

    public function category(): string
    {
        return 'documents';
    }

    public function purge(DateTimeImmutable $now): int
    {
        $documents = $this->documents->findSoftDeletedBefore(
            $this->settings->documentsThreshold($now),
            $this->settings->purgeBatchSize,
        );

        $removed = 0;

        foreach ($documents as $document) {
            try {
                $this->storage->delete($document->getStorageKey());
            } catch (Throwable) {
                // El fichero ya no está: la fila se borra igualmente.
            }

            $this->documents->remove($document);
            ++$removed;
        }

        return $removed;
    }
}
