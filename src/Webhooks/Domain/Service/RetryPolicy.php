<?php

declare(strict_types=1);

namespace App\Webhooks\Domain\Service;

use function count;

use DateTimeImmutable;
use DateTimeZone;

use function sprintf;

/**
 * Cuándo se reintenta una entrega fallida (Fase 20).
 *
 * La espera crece con cada intento y tiene un tope: un receptor que lleva horas
 * caído no se arregla martilleándolo cada minuto, pero tampoco se le da por
 * perdido a la primera. La secuencia suma algo menos de dos días, de modo que
 * un corte de mantenimiento de una noche se recupera solo y un receptor
 * realmente muerto se abandona pronto en lugar de ocupar la cola para siempre.
 *
 * Es una clase y no una constante suelta porque los tests necesitan comprobar
 * los saltos sin esperar: `nextAttemptAt()` recibe el instante de partida.
 */
final readonly class RetryPolicy
{
    /**
     * Esperas en segundos, indexadas por el número de intento ya realizado.
     *
     * `attempts = 1` significa «falló el primer envío», así que la primera
     * espera es la del índice 0.
     */
    private const array DELAYS = [
        30,        // 30 s
        120,       // 2 min
        600,       // 10 min
        3600,      // 1 h
        21600,     // 6 h
        86400,     // 24 h
        86400,     // 24 h
    ];

    /**
     * Reintentos programables: uno por cada espera de la tabla.
     *
     * Ojo con la suma: el primer envío no es un reintento, así que el número
     * total de envíos de una entrega es `maxRetries() + 1`.
     */
    public function maxRetries(): int
    {
        return count(self::DELAYS);
    }

    /**
     * Envíos totales antes de abandonar: el primero más sus reintentos.
     */
    public function maxDeliveries(): int
    {
        return $this->maxRetries() + 1;
    }

    /**
     * Instante del siguiente intento, o `null` si ya no quedan.
     */
    public function nextAttemptAt(int $attempts, DateTimeImmutable $failedAt): ?DateTimeImmutable
    {
        $index = $attempts - 1;

        if ($index < 0 || $index >= count(self::DELAYS)) {
            return null;
        }

        return $failedAt->setTimezone(new DateTimeZone('UTC'))->modify(sprintf('+%d seconds', self::DELAYS[$index]));
    }

    /**
     * Días tras los cuales una entrega pendiente se abandona aunque le queden
     * intentos: si el proceso estuvo parado, no tiene sentido reanudar algo de
     * hace una semana con el receptor ya arreglado.
     */
    public function abandonAfterDays(): int
    {
        return 7;
    }
}
