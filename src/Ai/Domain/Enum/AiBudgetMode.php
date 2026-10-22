<?php

declare(strict_types=1);

namespace App\Ai\Domain\Enum;

/**
 * Qué pasa cuando la organización se queda sin presupuesto de IA (Fase 14).
 *
 * ## Por qué hay dos modos y no un interruptor
 *
 * Un tope único obliga a elegir entre dos cosas malas: seguir gastando o
 * dejar de trabajar. Ninguna de las dos es lo que quiere el cliente, así que
 * el tope tiene **dos formas** de incumplirse, y el cliente elige:
 *
 * | Modo | Al agotarse el presupuesto | Consecuencia |
 * |---|---|---|
 * | `SOFT` | Se sigue usando la IA **económica** | Solo se pierde el nivel 6 (IA avanzada) |
 * | `HARD` | No se llama a ningún modelo | Los correos que necesitaban IA quedan `DEFERRED` |
 *
 * El modo `SOFT` es el valor por defecto porque degrada en lugar de parar:
 * el documento sale, solo que sin el modelo grande. El modo `HARD` existe para
 * quien paga la factura y quiere un cero absoluto, y para quien necesita que
 * el consumo sea predecible al céntimo.
 */
enum AiBudgetMode: string
{
    /**
     * Se degrada: la IA económica sigue funcionando, la avanzada se apaga.
     */
    case SOFT = 'soft';

    /**
     * Se para: no se llama a ningún modelo hasta que empiece otro mes.
     */
    case HARD = 'hard';

    public function label(): string
    {
        return match ($this) {
            self::SOFT => 'Degradar (seguir con IA económica)',
            self::HARD => 'Parar (aplazar los correos que necesiten IA)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SOFT => 'Al llegar al tope se sigue usando la IA económica, pero deja de usarse la IA avanzada, que es la cara.',
            self::HARD => 'Al llegar al tope no se llama a ningún modelo. Los correos que necesitaban IA quedan aplazados hasta el mes siguiente.',
        };
    }
}
