<?php

declare(strict_types=1);

namespace App\Observability\Application\Dto;

/**
 * Actividad de sincronización de los buzones de la organización.
 *
 * Responde a la pregunta que un operador se hace cuando algo no aparece:
 * «¿ha pasado el correo?». La respuesta necesita tres cosas: cuántas
 * pasadas hubo, cuánto se vio en cada una y cuánto se procesó.
 */
final readonly class SyncActivity
{
    public function __construct(
        /** Número de pasadas de sincronización en el periodo. */
        public int $runs,
        /** Pasadas que terminaron en error. */
        public int $failed,
        /** Mensajes encontrados en total. */
        public int $seen,
        /** Mensajes procesados (extraídos y clasificados). */
        public int $processed,
        /** Mensajes descartados por el filtro determinista. */
        public int $skipped,
        /** Mensajes recuperados del histórico (backfill). */
        public int $backfilled,
        /** Descubrimientos creados a partir de los correos. */
        public int $discoveries,
        /** Duración media de una pasada, en milisegundos. */
        public int $averageDurationMs,
    ) {
    }

    public function hasActivity(): bool
    {
        return $this->runs > 0;
    }

    public function failureRate(): float
    {
        if ($this->runs === 0) {
            return 0.0;
        }

        return $this->failed / $this->runs;
    }

    /**
     * Porcentaje de mensajes vistos que acabarón en un descubrimiento.
     *
     * Un valor muy bajo no es necesariamente un problema (la mayoría de los
     * correos son ruido), pero un valor de cero con muchos mensajes vistos
     * sí lo es: el pipeline está funcionando y no encuentra nada.
     */
    public function discoveryRate(): float
    {
        if ($this->seen === 0) {
            return 0.0;
        }

        return $this->discoveries / $this->seen;
    }
}
