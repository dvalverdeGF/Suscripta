<?php

declare(strict_types=1);

namespace App\Discovery\Application\Privacy;

use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Shared\Application\Privacy\OrganizationDataProviderInterface;

use function count;

use Symfony\Component\Uid\Uuid;

/**
 * Sección «discoveries» de la exportación: lo que el sistema ha detectado.
 *
 * Un descubrimiento es un **hecho con propuesta**: el sistema ha visto algo
 * que parece una suscripción y propone un servicio. Se exporta con su
 * confianza, su estado y las razones del emparejamiento, para que el usuario
 * pueda revisar por qué el sistema propone lo que propone.
 */
final readonly class DiscoveryDataProvider implements OrganizationDataProviderInterface
{
    public function __construct(
        private DiscoveryRepositoryInterface $discoveries,
    ) {
    }

    public function section(): string
    {
        return 'discoveries';
    }

    public function export(Uuid $organizationId): array
    {
        $discoveries = $this->discoveries->findAllForOrganization($organizationId);

        $rows = [];

        foreach ($discoveries as $discovery) {
            $rows[] = [
                'id' => $discovery->getId()->toRfc4122(),
                'type' => $discovery->getType()->value,
                'status' => $discovery->getStatus()->value,
                'confidence' => $discovery->getConfidence()->value,
                'confidenceScore' => $discovery->getConfidenceScore(),
                'matchScore' => $discovery->getMatchScore(),
                'matchReasons' => $discovery->getMatchReasons(),
                'proposedData' => $discovery->getProposedData(),
                'matchedServiceId' => $discovery->getMatchedServiceId()?->toRfc4122(),
                'resultingServiceId' => $discovery->getResultingServiceId()?->toRfc4122(),
                'sourceEmailMessageId' => $discovery->getSourceEmailMessageId()?->toRfc4122(),
                'extractionTier' => $discovery->getExtractionTier()?->value,
                'detectedAt' => $discovery->getDetectedAt()->format('c'),
                'reviewedAt' => $discovery->getReviewedAt()?->format('c'),
                'notes' => $discovery->getNotes(),
            ];
        }

        return [
            'meta' => ['count' => count($rows)],
            'rows' => $rows,
        ];
    }
}
