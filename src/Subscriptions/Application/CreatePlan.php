<?php

declare(strict_types=1);

namespace App\Subscriptions\Application;

use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Subscriptions\Application\Dto\PlanInput;
use App\Subscriptions\Domain\Entity\Plan;
use App\Subscriptions\Domain\Repository\PlanRepositoryInterface;

use function sprintf;

/**
 * Da de alta un plan en el catálogo comercial.
 *
 * El código es la unión con los límites de la aplicación: si ya hay un plan con
 * ese código, este segundo sería un duplicado que la base de datos rechazaría
 * con un error de integridad. Se comprueba antes para poder decírselo al
 * operador en su idioma.
 */
final readonly class CreatePlan
{
    public function __construct(
        private PlanRepositoryInterface $plans,
    ) {
    }

    public function __invoke(PlanInput $input): Plan
    {
        $existing = $this->plans->findByCode($input->code);

        if (null !== $existing) {
            throw new InvalidArgumentException(sprintf('Ya hay un plan en el catálogo para «%s». Edita el existente en vez de crear otro.', $input->code->label()));
        }

        $plan = new Plan($input->code, $input->name, $input->position);
        $plan->describe($input->tagline, $input->description);
        $plan->setActive($input->isActive);
        $plan->setHighlighted($input->isHighlighted);

        $this->plans->save($plan);

        return $plan;
    }
}
