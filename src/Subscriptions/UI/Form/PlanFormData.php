<?php

declare(strict_types=1);

namespace App\Subscriptions\UI\Form;

use App\Identity\Domain\Enum\OrganizationPlan;
use App\Subscriptions\Application\Dto\PlanInput;
use App\Subscriptions\Domain\Entity\Plan;

/**
 * Puente mutable entre el formulario y `PlanInput`.
 *
 * Las propiedades son anulables porque un campo no requerido que llega vacío se
 * normaliza a `null`, y una propiedad no anulable convertiría el envío en un
 * error 500 en lugar de un error de validación.
 */
final class PlanFormData
{
    public ?OrganizationPlan $code = null;
    public ?string $name = null;
    public ?string $tagline = null;
    public ?string $description = null;
    public bool $isActive = true;
    public bool $isHighlighted = false;
    public ?int $position = 0;

    public static function fromPlan(Plan $plan): self
    {
        $data = new self();
        $data->code = $plan->getCode();
        $data->name = $plan->getName();
        $data->tagline = $plan->getTagline();
        $data->description = $plan->getDescription();
        $data->isActive = $plan->isActive();
        $data->isHighlighted = $plan->isHighlighted();
        $data->position = $plan->getPosition();

        return $data;
    }

    public function toInput(): PlanInput
    {
        return new PlanInput(
            code: $this->code ?? OrganizationPlan::default(),
            name: (string) $this->name,
            tagline: $this->tagline,
            description: $this->description,
            isActive: $this->isActive,
            isHighlighted: $this->isHighlighted,
            position: $this->position ?? 0,
        );
    }
}
