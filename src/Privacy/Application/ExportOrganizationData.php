<?php

declare(strict_types=1);

namespace App\Privacy\Application;

use App\Privacy\Application\Dto\DataExport;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\Privacy\OrganizationDataProviderInterface;
use App\Shared\Domain\Enum\AuditAction;
use Symfony\Component\Uid\Uuid;

/**
 * Exporta todos los datos de una organización (derecho de acceso y portabilidad).
 *
 * No pregunta a ningún módulo por sus entidades: cada módulo publica una
 * sección mediante `OrganizationDataProviderInterface` y este caso de uso se
 * limita a juntarlas. Así, añadir un módulo con datos que exportar es escribir
 * una clase, y el agregador no se entera.
 *
 * **Lo que no se exporta:** credenciales de buzón, claves de proveedores de
 * IA y valores cifrados de integraciones. Son secretos, no datos del usuario,
 * y un archivo de exportación viaja por el correo (SECURITY.md §9).
 */
final readonly class ExportOrganizationData
{
    /**
     * @param iterable<OrganizationDataProviderInterface> $providers
     */
    public function __construct(
        private iterable $providers,
        private AuditLoggerInterface $auditLogger,
        private Clock $clock,
    ) {
    }

    public function __invoke(Uuid $organizationId, ?Uuid $actorUserId = null): DataExport
    {
        $sections = [];

        foreach ($this->providers as $provider) {
            $sections[$provider->section()] = $provider->export($organizationId);
        }

        ksort($sections);

        $export = new DataExport($sections, $this->clock->now());

        $this->auditLogger->log(
            action: AuditAction::DATA_EXPORTED,
            targetType: 'organization',
            targetId: $organizationId->toRfc4122(),
            metadata: [
                'sections' => implode(',', $export->sectionNames()),
                'rows' => $export->rowCount(),
            ],
            organizationId: $organizationId,
            actorUserId: $actorUserId,
        );

        return $export;
    }
}
