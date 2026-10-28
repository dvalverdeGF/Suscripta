<?php

declare(strict_types=1);

namespace App\Subscriptions\Application;

use App\Subscriptions\Application\Dto\PlanPriceInput;
use App\Subscriptions\Domain\Entity\Plan;
use App\Subscriptions\Domain\Entity\PlanPrice;
use App\Subscriptions\Domain\Repository\PlanPriceRepositoryInterface;
use DateTimeImmutable;

/**
 * Pone un precio a la venta y cierra el que hubiera para el mismo periodo y
 * moneda.
 *
 * ## Por qué se cierra en vez de sobrescribir
 *
 * Un precio es un dato con fecha (D-13). Si se mutara el importe, la pregunta
 * «¿cuánto costaba en marzo?» dejaría de tener respuesta. Cerrar la fila
 * anterior y abrir una nueva deja el historial completo y, de paso, hace que
 * `validTo IS NULL` signifique exactamente «esto es lo que se ofrece hoy».
 *
 * ## Un plan no puede tener dos precios mensuales vigentes en euros
 *
 * Sería una oferta ambigua: ¿cuál se cobra? La comprobación la hace
 * `findCurrent()` antes de insertar, así que la regla vive en un sitio y no se
 * repite en cada llamador.
 *
 * ## Publicar el mismo importe no crea una fila nueva
 *
 * Si el operador guarda el formulario sin cambiar nada, no hay motivo para
 * abrir un periodo de precio idéntico al anterior: se devuelve el vigente.
 */
final readonly class PublishPlanPrice
{
    public function __construct(
        private PlanPriceRepositoryInterface $prices,
    ) {
    }

    public function __invoke(Plan $plan, PlanPriceInput $input, ?DateTimeImmutable $validFrom = null): PlanPrice
    {
        $validFrom ??= new DateTimeImmutable('today');
        $current = $this->prices->findCurrent($plan->getId(), $input->billingPeriod, $input->amount->currency);

        if (null !== $current && $current->getAmountMinor() === $input->amount->amountMinor) {
            $current->attachExternalPriceId($input->externalPriceId ?? $current->getExternalPriceId());
            $this->prices->save($current);

            return $current;
        }

        if (null !== $current) {
            $current->close($validFrom);
            $this->prices->save($current);
        }

        $price = new PlanPrice($plan, $input->amount, $input->billingPeriod, $validFrom);
        $price->attachExternalPriceId($input->externalPriceId);
        $this->prices->save($price);

        return $price;
    }
}
