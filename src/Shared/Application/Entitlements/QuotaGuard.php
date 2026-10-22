<?php

declare(strict_types=1);

namespace App\Shared\Application\Entitlements;

use App\Shared\Application\OrganizationSettingsReader;
use App\Shared\Domain\Exception\QuotaExceededException;
use LogicException;

use function sprintf;

use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Cruza el límite del plan con el uso real de la organización (Fase 17).
 *
 * ## Por qué dos piezas
 *
 * El **límite** lo dice la configuración del plan; el **uso** lo sabe el
 * módulo que tiene los datos (servicios, buzones, documentos). Ninguno de los
 * dos sabe lo suficiente por sí solo, así que el guard los cruza:
 *
 * - `EntitlementCheckerInterface` → el límite.
 * - `EntitlementUsageProviderInterface` → el uso, publicado por cada módulo.
 *
 * ## Dónde se comprueba
 *
 * En el **caso de uso**, no en el controlador: un caso de uso que se llama
 * desde la API, de la interfaz o de un comando tiene que respetar el mismo
 * límite. Si la comprobación viviera en el controlador, la API podría saltarse
 * el plan sin querer (Fase 18).
 */
final readonly class QuotaGuard implements QuotaCheckerInterface
{
    /**
     * @param ServiceProviderInterface<EntitlementUsageProviderInterface> $usageProviders
     */
    public function __construct(
        private EntitlementCheckerInterface $entitlements,
        private EntitlementSettings $settings,
        private OrganizationSettingsReader $organizations,
        private ServiceProviderInterface $usageProviders,
    ) {
    }

    /**
     * Límite del plan para esta capacidad (`null` = sin techo).
     */
    public function limit(string $capability): ?int
    {
        return $this->entitlements->limit($capability);
    }

    /**
     * ¿El plan ofrece esta capacidad?
     */
    public function allows(string $capability): bool
    {
        return $this->entitlements->allows($capability);
    }

    /**
     * Cuántas unidades usa ahora mismo la organización activa.
     */
    public function usage(string $capability): int
    {
        if (!$this->usageProviders->has($capability)) {
            throw new LogicException(sprintf('No hay ningún proveedor de uso para la capacidad "%s".', $capability));
        }

        return $this->usageProviders->get($capability)->usage();
    }

    /**
     * Foto del consumo de una capacidad.
     */
    public function status(string $capability): QuotaStatus
    {
        $limit = $this->entitlements->limit($capability);
        $allowed = $this->entitlements->allows($capability);

        $usage = 0;

        if ($allowed && $this->usageProviders->has($capability)) {
            $usage = $this->usageProviders->get($capability)->usage();
        }

        return new QuotaStatus(
            capability: $capability,
            label: $this->settings->labelFor($capability),
            unit: $this->settings->unitFor($capability),
            usage: $usage,
            limit: $limit,
            allowed: $allowed,
        );
    }

    /**
     * Foto del consumo de todas las capacidades declaradas.
     *
     * @return list<QuotaStatus>
     */
    public function statuses(): array
    {
        $statuses = [];

        foreach ($this->settings->capabilityNames() as $capability) {
            $statuses[] = $this->status($capability);
        }

        return $statuses;
    }

    /**
     * ¿Queda hueco para una unidad más de esta capacidad?
     */
    public function isWithinQuota(string $capability): bool
    {
        $status = $this->status($capability);

        if ($status->isBlocked()) {
            return false;
        }

        if ($status->isUnlimited()) {
            return true;
        }

        return $status->usage < $status->limit;
    }

    /**
     * Comprueba que queda hueco y, si no, lanza la excepción.
     *
     * @throws QuotaExceededException cuando el plan ya no da para más
     */
    public function assertWithinQuota(string $capability): void
    {
        $status = $this->status($capability);

        if ($status->isBlocked()) {
            throw $this->exception($status);
        }

        if ($status->isUnlimited() || $status->usage < $status->limit) {
            return;
        }

        throw $this->exception($status);
    }

    /**
     * Nombre legible del plan activo.
     */
    public function planLabel(): string
    {
        $organization = $this->organizations->current();

        if (null === $organization) {
            return '';
        }

        return $this->settings->planLabel($organization->getPlan());
    }

    private function exception(QuotaStatus $status): QuotaExceededException
    {
        return new QuotaExceededException(
            capability: $status->capability,
            label: $status->label,
            usage: $status->usage,
            limit: $status->limit,
            planLabel: $this->planLabel(),
        );
    }
}
