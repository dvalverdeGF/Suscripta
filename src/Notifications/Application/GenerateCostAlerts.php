<?php

declare(strict_types=1);

namespace App\Notifications\Application;

use App\Ai\Application\AiBudgetGuard;
use App\Ai\Application\Dto\AiBudgetStatus;
use App\Notifications\Application\Dto\CostAlertResult;
use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Repository\AlertRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;

use function sprintf;

/**
 * Avisa al usuario cuando el presupuesto de IA se está agotando (Fase 14).
 *
 * ## Por qué vive aquí y no en `GenerateAlerts`
 *
 * `GenerateAlerts` decide qué avisos deberían tener abiertos **a partir del
 * estado del negocio**: cobros, renovaciones, precios. Sus reglas están en
 * `AlertRules` y son deterministas, sin una sola llamada a IA.
 *
 * El presupuesto de IA es otra cosa: su estado lo lleva un módulo que el
 * generador de avisos no debería conocer. Meterlo en `AlertRules::generatedTypes()`
 * obligaría a `GenerateAlerts` a resolver avisos de coste en cada barrido, y a
 * `AlertRules` a saber qué es un presupuesto de IA.
 *
 * Aquí se resuelve justo lo contrario: un servicio pequeño que **solo** mira el
 * presupuesto y **solo** gestiona sus propios avisos. El módulo de `Ai` no
 * depende de `Notifications`: la dependencia va en el sentido correcto, de los
 * avisos hacia el presupuesto.
 *
 * ## Un aviso por mes
 *
 * La clave de deduplicación incluye el último día del mes, así que:
 *
 * - el mismo mes no genera dos avisos aunque el comando se ejecute cada hora;
 * - al cambiar de mes, el aviso viejo se resuelve y aparece uno nuevo.
 *
 * Y si dentro del mes el estado pasa de «aviso» a «agotado», el aviso de
 * aviso se resuelve y se crea el de agotado: los dos a la vez serían ruido.
 *
 * Si la situación **vuelve** a darse dentro del mismo mes (el cliente sube el
 * límite y vuelve a acercarse al tope), el aviso que el sistema había
 * resuelto se reabre. La clave de deduplicación es única, así que no se puede
 * crear otro, y el usuario necesita enterarse otra vez.
 */
final readonly class GenerateCostAlerts
{
    public function __construct(
        private AiBudgetGuard $budget,
        private AlertRepositoryInterface $alerts,
        private TenantContext $tenantContext,
        private Clock $clock,
    ) {
    }

    public function __invoke(): CostAlertResult
    {
        $organizationId = $this->tenantContext->requireOrganizationId();
        $now = $this->clock->now();
        $status = $this->budget->status();

        $type = $this->typeFor($status);
        $expectedKey = null === $type ? null : $this->dedupKeyFor($type, $now);

        $created = 0;
        $resolved = 0;
        $unchanged = 0;
        $reopened = 0;

        foreach ([AlertType::AI_BUDGET_WARNING, AlertType::AI_BUDGET_EXCEEDED] as $candidate) {
            foreach ($this->alerts->findOpenByType($candidate) as $open) {
                if (null !== $expectedKey && $open->getDedupKey() === $expectedKey) {
                    ++$unchanged;

                    continue;
                }

                $open->resolve($now);
                ++$resolved;
            }
        }

        if (null !== $type && null !== $expectedKey) {
            $existing = $this->alerts->findByDedupKey($expectedKey);

            if (null === $existing) {
                $alert = new Alert(
                    organizationId: $organizationId,
                    type: $type,
                    severity: $this->severityFor($type),
                    title: $this->titleFor($type),
                    message: $this->messageFor($type, $status),
                    dedupKey: $expectedKey,
                    dueAt: $this->lastDayOfMonth($now),
                    createdAt: $now,
                );

                $alert->setMetadata($this->metadataFor($status));

                $this->alerts->save($alert, false);
                ++$created;
            } else {
                // El aviso de este mes ya existe. Si el sistema lo resolvió
                // (porque la situación dejó de darse) y la situación ha vuelto,
                // se reabre: el identificador único de la clave impide crear
                // otro, y el usuario necesita enterarse otra vez.
                if ($existing->reopen()) {
                    ++$reopened;
                } elseif (!$existing->isOpen()) {
                    // Lo descartó el usuario: no se reabre. Se cuenta como
                    // «sin cambios» para que quede constancia de que el aviso
                    // sigue ahí, pero sin tocarlo.
                    ++$unchanged;
                }

                $existing->setMetadata($this->metadataFor($status));
                $this->alerts->save($existing, false);
            }
        }

        if ($created > 0 || $resolved > 0 || $reopened > 0) {
            $this->alerts->flush();
        }

        return new CostAlertResult(
            created: $created,
            resolved: $resolved,
            unchanged: $unchanged,
            exhausted: $status->exhausted,
            warning: $status->warning,
            reopened: $reopened,
        );
    }

    /**
     * Los datos que acompañan al aviso, para que el panel pueda explicarlo
     * sin tener que recalcular nada.
     *
     * @return array<string, mixed>
     */
    private function metadataFor(AiBudgetStatus $status): array
    {
        return [
            'period' => $this->budget->currentPeriodKey(),
            'limit_minor' => $status->limitMinor,
            'spent_minor' => $status->spentMinor,
            'used_percent' => $status->usedPercent,
            'mode' => $status->mode->value,
        ];
    }

    /**
     * ¿Qué tipo de aviso corresponde al estado del presupuesto?
     */
    private function typeFor(AiBudgetStatus $status): ?AlertType
    {
        if ($status->exhausted) {
            return AlertType::AI_BUDGET_EXCEEDED;
        }

        if ($status->warning) {
            return AlertType::AI_BUDGET_WARNING;
        }

        return null;
    }

    private function severityFor(AlertType $type): AlertSeverity
    {
        return AlertType::AI_BUDGET_EXCEEDED === $type ? AlertSeverity::CRITICAL : AlertSeverity::WARNING;
    }

    private function titleFor(AlertType $type): string
    {
        return AlertType::AI_BUDGET_EXCEEDED === $type
            ? 'Se ha agotado el presupuesto de IA'
            : 'El presupuesto de IA se está agotando';
    }

    /**
     * El mensaje dice qué ha pasado y qué se puede hacer.
     */
    private function messageFor(AlertType $type, AiBudgetStatus $status): string
    {
        $spent = $this->format($status->spentMinor);
        $limit = $this->format($status->limitMinor);

        if (AlertType::AI_BUDGET_EXCEEDED === $type) {
            return sprintf(
                'Este mes ya se han gastado %s de los %s disponibles en IA. '
                .'Hasta que subas el límite o termine el mes, los correos que necesiten IA se guardan sin procesar '
                .'y se reintentan más tarde.',
                $spent,
                $limit,
            );
        }

        return sprintf(
            'Este mes ya se han gastado %s de los %s disponibles en IA (%d %%). '
            .'Si el gasto se mantiene, el presupuesto se agotará antes de que termine el mes.',
            $spent,
            $limit,
            $status->usedPercent,
        );
    }

    /**
     * Importe en euros legibles.
     */
    private function format(int $minor): string
    {
        return Money::of($minor, Currency::EUR)->format();
    }

    /**
     * La clave de deduplicación del aviso de este mes.
     */
    private function dedupKeyFor(AlertType $type, DateTimeImmutable $now): string
    {
        return Alert::buildDedupKey($type, null, $this->lastDayOfMonth($now));
    }

    /**
     * El último día del mes: la «fecha objetivo» del aviso.
     */
    private function lastDayOfMonth(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify('last day of this month 00:00:00');
    }
}
