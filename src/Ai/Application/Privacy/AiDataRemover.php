<?php

declare(strict_types=1);

namespace App\Ai\Application\Privacy;

use App\Ai\Domain\Repository\AiBudgetRepositoryInterface;
use App\Ai\Domain\Repository\AiUsageRepositoryInterface;
use App\Shared\Application\Privacy\OrganizationDataRemoverInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Borra el historial de consumo de IA y los presupuestos de la organización.
 *
 * Se borra el **uso** (histórico) pero no la **configuración** de proveedores,
 * que vive en el módulo de integraciones y se borra en su propio turno.
 */
final readonly class AiDataRemover implements OrganizationDataRemoverInterface
{
    public function __construct(
        private AiUsageRepositoryInterface $usage,
        private AiBudgetRepositoryInterface $budgets,
    ) {
    }

    public function module(): string
    {
        return 'ai';
    }

    /**
     * El uso de IA no apunta a nada que siga vivo: se va en cualquier momento.
     */
    public function priority(): int
    {
        return 50;
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        $usageRemoved = $this->usage->removeForOrganization($organizationId);
        $budgetsRemoved = $this->budgets->removeForOrganization($organizationId);

        return $usageRemoved + $budgetsRemoved;
    }
}
