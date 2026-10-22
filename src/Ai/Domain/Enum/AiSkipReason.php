<?php

declare(strict_types=1);

namespace App\Ai\Domain\Enum;

/**
 * Por qué la escalera de IA no ha producido un documento (Fase 14).
 *
 * ## Por qué hace falta el motivo
 *
 * Antes de la Fase 14, `enrich()` devolvía `null` y no decía por qué. Eso
 * obligaba al pipeline a tratar igual cuatro situaciones muy distintas:
 * la IA está apagada, el presupuesto se ha agotado, el proveedor ha fallado y
 * el texto no tenía nada que contar. Para el usuario todas se ven igual: «el
 * correo necesita revisión».
 *
 * Pero **no son lo mismo**, y el sistema tiene que poder distinguirlas:
 *
 * - Si el presupuesto está agotado, el correo **no está mal**: está esperando.
 *   Se queda en `DEFERRED` y se reprocesa cuando haya presupuesto.
 * - Si el proveedor ha fallado, el correo **se ha revisado** y no va a
 *   mejorar por reintentar: pasa a revisión manual.
 *
 * Confundir los dos casos es lo que convierte un tope de gasto en una lista
 * de correos perdidos.
 */
enum AiSkipReason: string
{
    /**
     * No es un caso omitido: la IA ha devuelto un documento.
     */
    case NONE = 'none';

    /**
     * La IA está desactivada para la organización (D-08).
     */
    case NOT_CONFIGURED = 'not_configured';

    /**
     * El texto no tenía nada que mandar al modelo.
     */
    case NO_TEXT = 'no_text';

    /**
     * Se ha agotado el presupuesto de IA del mes.
     *
     * Es el único motivo que **aplaza** el correo en lugar de mandarlo a
     * revisión: el documento es correcto, solo falta presupuesto.
     */
    case BUDGET_EXHAUSTED = 'budget_exhausted';

    /**
     * Se ha superado el límite de peticiones por minuto.
     */
    case RATE_LIMITED = 'rate_limited';

    /**
     * La IA no puede mejorar este documento.
     *
     * No es un fallo: es que la escalera no tiene nada que hacer aquí. Por
     * ejemplo, cuando la extracción determinista ya ha conseguido el importe
     * y lo que falta es la periodicidad: el modelo barato no entra (ya sabe
     * el importe) y el caro solo se escala desde el barato.
     */
    case NOT_NEEDED = 'not_needed';

    /**
     * El proveedor ha fallado o ha devuelto una respuesta que no supera la
     * validación.
     */
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Procesado con IA',
            self::NOT_CONFIGURED => 'IA desactivada',
            self::NO_TEXT => 'Sin texto que analizar',
            self::BUDGET_EXHAUSTED => 'Presupuesto de IA agotado',
            self::RATE_LIMITED => 'Límite de peticiones alcanzado',
            self::NOT_NEEDED => 'La IA no puede mejorar este documento',
            self::FAILED => 'El proveedor de IA ha fallado',
        };
    }

    /**
     * ¿Este motivo hace que el correo se aplaze en lugar de ir a revisión?
     *
     * Solo el presupuesto: es la única razón por la que **más tarde** puede
     * estar resuelto sin intervención de nadie.
     */
    public function defersMessage(): bool
    {
        return self::BUDGET_EXHAUSTED === $this;
    }
}
