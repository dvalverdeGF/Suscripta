<?php

declare(strict_types=1);

namespace App\Ai\Application;

use App\Ai\Domain\Repository\AiUsageRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\DateRange;
use Symfony\Component\Uid\Uuid;

/**
 * Vigila el presupuesto de IA de cada organización (D-08).
 *
 * ## Por qué hace falta un tope
 *
 * «IA activada» sin tope es una factura sin límite. Un buzón con mil correos
 * mal formados, o un modelo que alucina y devuelve un JSON enorme, pueden
 * vaciar la cuenta del cliente en una noche. El presupuesto convierte la IA
 * en algo responsable: hay un máximo, y al superarlo el sistema **degrada**
 * en lugar de seguir gastando.
 *
 * ## Degradar, no cortar
 *
 * Al superar el presupuesto el pipeline no falla: el documento se queda en
 * `REQUIRES_REVIEW` y el usuario lo ve. Cortar el procesamiento entero dejaría
 * facturas sin procesar, que es peor que un correo más en la cola de revisión.
 *
 * ## El mes es el natural
 *
 * El presupuesto se reinicia cada mes natural, que es como se factura el
 * consumo de una API. Se cuenta el gasto desde el día 1 del mes actual hasta
 * el día 1 del siguiente.
 */
final readonly class AiBudget
{
    public function __construct(
        private AiUsageRepositoryInterface $usageRepository,
        private TenantContext $tenantContext,
        private Clock $clock,
        /**
         * Coste máximo mensual en céntimos. `0` significa «sin tope».
         */
        private int $monthlyMinor = 500,
    ) {
    }

    /**
     * ¿Se ha agotado el presupuesto de la organización activa?
     */
    public function isExhausted(): bool
    {
        $limit = $this->monthlyMinor;

        if ($limit <= 0) {
            return false;
        }

        return $this->spentMinor() >= $limit;
    }

    /**
     * Gasto del mes en curso, en céntimos.
     */
    public function spentMinor(): int
    {
        $organizationId = $this->organizationId();

        if (null === $organizationId) {
            return 0;
        }

        return $this->usageRepository->totalCostMinor($organizationId, $this->currentMonth());
    }

    /**
     * Tope mensual en céntimos (`0` = sin tope).
     */
    public function limitMinor(): int
    {
        return $this->monthlyMinor;
    }

    /**
     * Lo que queda de presupuesto, en céntimos.
     *
     * Nunca es negativo: si se ha pasado, queda `0`.
     */
    public function remainingMinor(): int
    {
        if ($this->monthlyMinor <= 0) {
            return 0;
        }

        return max(0, $this->monthlyMinor - $this->spentMinor());
    }

    /**
     * ¿Se puede permitir esta llamada?
     *
     * El margen de seguridad evita que la llamada que supera el tope se
     * cobre: se comprueba el gasto **previo**, que es lo que ya se ha pagado.
     */
    public function allowsCall(): bool
    {
        return !$this->isExhausted();
    }

    /**
     * Rango del mes natural en curso.
     */
    public function currentMonth(): DateRange
    {
        $now = $this->clock->now();

        return new DateRange(
            $now->modify('first day of this month 00:00:00'),
            $now->modify('first day of next month 00:00:00'),
        );
    }

    private function organizationId(): ?Uuid
    {
        return $this->tenantContext->getOrganizationId();
    }
}
