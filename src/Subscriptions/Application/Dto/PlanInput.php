<?php

declare(strict_types=1);

namespace App\Subscriptions\Application\Dto;

use App\Identity\Domain\Enum\OrganizationPlan;

/**
 * Datos de alta o edición de un plan del catálogo, ya validados por el
 * formulario.
 */
final readonly class PlanInput
{
    public function __construct(
        public OrganizationPlan $code,
        public string $name,
        public ?string $tagline = null,
        public ?string $description = null,
        public bool $isActive = true,
        public bool $isHighlighted = false,
        public int $position = 0,
    ) {
    }
}
