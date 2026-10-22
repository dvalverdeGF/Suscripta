<?php

declare(strict_types=1);

namespace App\Ai\Application\Dto;

use App\Ai\Domain\Enum\AiTier;
use App\Ai\Domain\ValueObject\AiCost;
use App\Mailbox\Domain\Enum\ExtractionTier;

/**
 * El informe de coste y rendimiento de la IA de un periodo (Fase 14).
 *
 * ## Qué mide y por qué
 *
 * El panel no enseña solo cuánto se ha gastado: enseña **qué se ha gastado
 ***. Un total sin desglose no sirve para decidir nada, porque lo que el
 * operador quiere saber es si el dinero se va en el proveedor caro, en el
 * nivel avanzado o en llamadas que no han servido para nada.
 *
 * Las tres cifras que resumen el panel son:
 *
 * | Cifra | Pregunta que responde |
 * |---|---|
 * | `resolvedWithoutAiPercent` | ¿Cuánto trabajo resuelven las reglas gratis? |
 * | `escalationRatePercent` | ¿Cuántos casos necesitan el modelo caro? |
 * | `averageCostPerCallMinor` | ¿Cuánto cuesta de media cada llamada? |
 *
 * ## Porcentajes sin denominador
 *
 * Cuando no hay llamadas, los porcentajes son `0` y no `null`: un panel que
 * dice «—» obliga al operador a decidir si es un dato que falta o un cero.
 *
 * ## Los importes van en microcéntimos
 *
 * Los costes se guardan y se suman en microcéntimos (D-68) y se redondean a
 * céntimos **al pintar**. Si se redondearan al guardar, el panel enseñaría
 * «0,00 €» para un gasto real de céntimas, que es el caso normal cuando se
 * usan modelos económicos.
 */
final readonly class AiCostReport
{
    /**
     * @param array<string, int> $costByProvider coste en microcéntimos
     * @param array<string, int> $costByPurpose  coste en microcéntimos
     * @param array<string, int> $callsByTier    valor de `AiTier` => llamadas
     * @param array<string, int> $durationByTier valor de `AiTier` => duración media
     * @param array<string, int> $messagesByTier valor de `ExtractionTier` => correos
     */
    public function __construct(
        public AiBudgetStatus $budget,
        public int $totalCostMicro,
        public int $calls,
        public int $promptTokens,
        public int $completionTokens,
        public int $failures,
        public int $averageDurationMs,
        public array $costByProvider,
        public array $costByPurpose,
        public array $callsByTier,
        public array $durationByTier,
        public int $messagesTotal,
        public array $messagesByTier,
        public int $averageProcessingMs,
        /**
         * Correos que están esperando presupuesto de IA.
         *
         * No es una cifra del periodo: son los que **ahora mismo** están
         * aplazados. Su número es el que explica por qué el gasto del mes
         * puede quedarse por debajo del tope: no es que no se gaste, es que
         * no se puede gastar más.
         */
        public int $deferredMessages = 0,
    ) {
    }

    /**
     * Coste total, en céntimos.
     */
    public function totalCostMinor(): int
    {
        return AiCost::toMinor($this->totalCostMicro);
    }

    /**
     * Porcentaje de mensajes resueltos **sin** IA.
     *
     * Es la cifra que justifica el pipeline determinista: si el 90 % de los
     * correos se resuelve con reglas, la IA solo está pagando por el 10 %
     * difícil, que es donde de verdad hace falta.
     */
    public function resolvedWithoutAiPercent(): int
    {
        if ($this->messagesTotal <= 0) {
            return 0;
        }

        $withoutAi = ($this->messagesByTier[ExtractionTier::DETERMINISTIC->value] ?? 0)
            + ($this->messagesByTier[ExtractionTier::KNOWN_PARSER->value] ?? 0);

        return (int) round(($withoutAi / $this->messagesTotal) * 100);
    }

    /**
     * Porcentaje de llamadas que han necesitado el nivel avanzado.
     *
     * Un valor alto significa que el nivel barato no está resolviendo: o el
     * modelo económico es demasiado débil, o el prompt se puede mejorar.
     *
     * Ojo con los dos niveles de extracción que hay en el sistema: aquí se
     * cuenta el **nivel de la llamada** (`AiTier`), no el nivel con el que se
     * resolvió el correo (`ExtractionTier`). Son cosas distintas: un correo
     * puede acabar resuelto por las reglas después de que la IA fallara, y
     * entonces no es una llamada avanzada que sirva de nada.
     */
    public function escalationRatePercent(): int
    {
        if ($this->calls <= 0) {
            return 0;
        }

        $advanced = $this->callsByTier[AiTier::ADVANCED->value] ?? 0;

        return (int) round(($advanced / $this->calls) * 100);
    }

    /**
     * Coste medio por llamada, en microcéntimos.
     *
     * Se calcula sobre el total en microcéntimos: redondeando el total
     * primero, el medio saldría «0,00 €» para un gasto que sí existe.
     */
    public function averageCostPerCallMicro(): int
    {
        if ($this->calls <= 0) {
            return 0;
        }

        return (int) round($this->totalCostMicro / $this->calls);
    }

    /**
     * Coste medio por llamada, en céntimos.
     */
    public function averageCostPerCallMinor(): int
    {
        return AiCost::toMinor($this->averageCostPerCallMicro());
    }

    /**
     * Porcentaje de llamadas que han fallado.
     */
    public function failureRatePercent(): int
    {
        if ($this->calls <= 0) {
            return 0;
        }

        return (int) round(($this->failures / $this->calls) * 100);
    }

    /**
     * ¿Hay algo que contar en este periodo?
     */
    public function isEmpty(): bool
    {
        return 0 === $this->calls && 0 === $this->messagesTotal;
    }
}
