<?php

declare(strict_types=1);

namespace App\Integrations\Application\Privacy;

use App\Integrations\Domain\Repository\IntegrationRepositoryInterface;
use App\Shared\Application\Privacy\OrganizationDataProviderInterface;

use function count;

use Symfony\Component\Uid\Uuid;

/**
 * Sección «integrations» de la exportación: las integraciones configuradas.
 *
 * **No se exportan las credenciales**, ni en claro ni cifradas: son secretos
 * (SECURITY.md §6). Lo que se exporta es el *hecho* de que hay una
 * credencial guardada, para que el usuario pueda ver qué está conectado y
 * decidir si lo revoca.
 *
 * Los ajustes (`settings`) se exportan porque son configuración de la
 * organización (por ejemplo, el proveedor de IA por defecto para cada
 * necesidad), no secretos.
 */
final readonly class IntegrationsDataProvider implements OrganizationDataProviderInterface
{
    public function __construct(
        private IntegrationRepositoryInterface $integrations,
    ) {
    }

    public function section(): string
    {
        return 'integrations';
    }

    public function export(Uuid $organizationId): array
    {
        $integrations = $this->integrations->findForOrganization($organizationId);

        $rows = [];

        foreach ($integrations as $integration) {
            $rows[] = [
                'id' => $integration->getId()->toRfc4122(),
                'key' => $integration->getKey(),
                'label' => $integration->getLabel(),
                'enabled' => $integration->isEnabled(),
                'hasCredentials' => $integration->hasCredentials(),
                'baseUrl' => $integration->getBaseUrl(),
                'settings' => $integration->getSettings(),
                'lastSuccessAt' => $integration->getLastSuccessAt()?->format('c'),
                'lastErrorAt' => $integration->getLastErrorAt()?->format('c'),
                'createdAt' => $integration->getCreatedAt()->format('c'),
                'updatedAt' => $integration->getUpdatedAt()->format('c'),
            ];
        }

        return [
            'meta' => ['count' => count($rows)],
            'rows' => $rows,
        ];
    }
}
