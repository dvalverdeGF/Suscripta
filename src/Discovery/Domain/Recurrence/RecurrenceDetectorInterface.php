<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Recurrence;

use DateTimeImmutable;

/**
 * Detecta la periodicidad de un servicio a partir de su historial de cobros.
 *
 * Es **determinista** por diseño (ARCHITECTURE.md §9): el patrón se deduce de
 * las distancias entre cobros, no de un modelo de lenguaje. Una suscripción
 * mensual son 30 días entre facturas; eso se puede comprobar, y una IA sólo
 * añadiría coste e incertidumbre.
 *
 * La interfaz no crea ni modifica servicios: sólo devuelve lo que ha deducido
 * para que otro caso de uso decida si lo aplica.
 */
interface RecurrenceDetectorInterface
{
    /**
     * @param list<RecurrenceSample> $samples cobros observados, en cualquier orden
     * @param DateTimeImmutable|null $now     referencia para no proponer fechas pasadas;
     *                                        null = sin recorte
     */
    public function detect(array $samples, ?DateTimeImmutable $now = null): RecurrenceResult;
}
