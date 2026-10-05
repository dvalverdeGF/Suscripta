<?php

declare(strict_types=1);

namespace App\Shared\Application;

use DateTimeImmutable;
use Symfony\Component\Clock\ClockInterface;

/**
 * Fachada del reloj del sistema.
 *
 * Todo el código de negocio obtiene "ahora" desde aquí, nunca con `new
 * \DateTimeImmutable()`. Así los tests pueden congelar el tiempo y el cálculo
 * de renovaciones es reproducible.
 */
final readonly class Clock
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    public function today(): DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0);
    }
}
