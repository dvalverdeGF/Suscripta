<?php

declare(strict_types=1);

namespace App\Discovery\Application\Change;

use App\Discovery\Domain\Enum\DiscoveryType;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceViewRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;

use function count;

/**
 * Barrido de detección de cambios sobre el inventario (D-59).
 *
 * Recorre el inventario de la organización y busca tres cosas que el pipeline
 * —que solo mira los documentos que llegan— no puede ver:
 *
 * - servicios que parecen el mismo dos veces,
 * - cobros que no llegaron,
 * - servicios que llevan meses sin moverse.
 *
 * El barrido **no decide nada**: propone sugerencias y las deja en la bandeja
 * de revisión. Y es **idempotente**: repetirlo no crea propuestas nuevas
 * para lo que ya se propuso, así que se puede lanzar cada noche sin miedo.
 */
final readonly class DetectChanges
{
    public function __construct(
        private ServiceViewRepositoryInterface $services,
        private DetectDuplicates $duplicates,
        private DetectMissingCharges $missingCharges,
        private DetectDormantServices $dormantServices,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(): ChangeDetectionResult
    {
        $services = $this->services->findAll(ServiceFilters::none());

        $proposals = [
            ...$this->duplicates->detect(),
            ...$this->missingCharges->detect(),
            ...$this->dormantServices->detect(),
        ];

        $counts = [];

        foreach ($proposals as $proposal) {
            $key = $proposal->getType()->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $result = new ChangeDetectionResult(
            proposals: $proposals,
            countsByType: $counts,
            servicesInspected: count($services),
        );

        $this->auditLogger->log(
            action: AuditAction::DISCOVERY_CREATED,
            targetType: 'discovery',
            targetId: 'sweep',
            metadata: [
                'sweep' => true,
                'services' => $result->servicesInspected,
                'proposals' => $result->total(),
                'duplicates' => $result->countOf(DiscoveryType::DUPLICATE),
                'missingCharges' => $result->countOf(DiscoveryType::MISSING_CHARGE),
                'dormant' => $result->countOf(DiscoveryType::DORMANT),
            ],
        );

        return $result;
    }
}
