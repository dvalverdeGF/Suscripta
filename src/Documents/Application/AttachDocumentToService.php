<?php

declare(strict_types=1);

namespace App\Documents\Application;

use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Domain\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Vincula un documento a un servicio.
 *
 * Es lo que convierte un fichero suelto en evidencia: a partir de aquí el
 * documento explica por qué sabemos lo que cuesta un servicio y desde cuándo.
 */
final readonly class AttachDocumentToService
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
        private ServiceRepositoryInterface $services,
    ) {
    }

    public function __invoke(Uuid $documentId, ?Uuid $serviceId, ?Uuid $actorUserId = null): void
    {
        $document = $this->documents->find($documentId);

        if (null === $document || $document->isDeleted()) {
            throw new InvalidArgumentException('El documento no existe.');
        }

        if (null !== $serviceId && null === $this->services->find($serviceId)) {
            throw new InvalidArgumentException('El servicio no existe.');
        }

        $document->attachToService($serviceId);
        $this->documents->save($document);

        if (null === $serviceId) {
            return;
        }

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
