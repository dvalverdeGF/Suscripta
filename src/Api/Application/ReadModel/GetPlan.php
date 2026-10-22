<?php

declare(strict_types=1);

namespace App\Api\Application\ReadModel;

use App\Shared\Application\Entitlements\QuotaGuard;

/**
 * Plan de la organización y consumo de cada límite.
 *
 * Un integrador necesita saber cuánto le queda antes de que la API le
 * devuelva un `402`: con estos datos puede avisar antes de que la integración
 * se quede a medias.
 */
final readonly class GetPlan
{
    public function __construct(private QuotaGuard $quotas)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        $quotas = [];

        foreach ($this->quotas->statuses() as $capability => $status) {
            $quotas[$capability] = [
                'label' => $status->label,
                'unit' => $status->unit,
                'usage' => $status->usage,
                'limit' => $status->limit,
                'allowed' => $status->allowed,
                'unlimited' => $status->isUnlimited(),
                'remaining' => $status->remaining(),
            ];
        }

        return [
            'plan' => $this->quotas->planLabel(),
            'quotas' => $quotas,
        ];
    }
}
