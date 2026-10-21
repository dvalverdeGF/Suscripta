<?php

declare(strict_types=1);

namespace App\Discovery\Application\Dto;

use function sprintf;

/**
 * Resultado de una revisión por lotes.
 *
 * Existe porque el usuario puede marcar propuestas que ya no están pendientes
 * (otra pestaña las ha confirmado) y la operación tiene que contarlo sin
 * abortar el lote entero.
 */
final readonly class BatchReviewResult
{
    public function __construct(
        public int $confirmed = 0,
        public int $dismissed = 0,
        public int $failed = 0,
    ) {
    }

    public function processed(): int
    {
        return $this->confirmed + $this->dismissed;
    }

    public function summary(): string
    {
        return sprintf(
            '%d confirmadas, %d descartadas y %d sin procesar.',
            $this->confirmed,
            $this->dismissed,
            $this->failed,
        );
    }
}
