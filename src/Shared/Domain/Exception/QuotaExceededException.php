<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

use function sprintf;

/**
 * La organización ha alcanzado el límite de su plan (Fase 17).
 *
 * Es una excepción de dominio y no un error HTTP: el caso de uso que la lanza
 * no sabe si lo está llamando un navegador, la API o un worker. Quien la
 * recibe decide cómo presentarla (la interfaz web la convierte en un aviso y
 * vuelve atrás; la API responderá con `402 Payment Required` en la fase 18).
 */
final class QuotaExceededException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $capability,
        public readonly string $label,
        public readonly int $usage,
        public readonly ?int $limit,
        public readonly string $planLabel,
    ) {
        parent::__construct(sprintf(
            'El plan %s permite %d %s y ya has usado %d.',
            $planLabel,
            $limit ?? 0,
            $label,
            $usage,
        ));
    }
}
