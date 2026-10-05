<?php

declare(strict_types=1);

namespace App\Notifications\Application\Dto;

/**
 * Qué ha pasado al generar avisos.
 *
 * Se devuelve en lugar de un entero porque el comando tiene que poder explicar
 * la diferencia entre «no había nada que avisar» y «ya estaba avisado», que es
 * justo lo que se mira cuando alguien dice que no le llegan los avisos.
 */
final readonly class AlertGenerationResult
{
    public function __construct(
        public int $created = 0,
        public int $resolved = 0,
        public int $unchanged = 0,
        public int $open = 0,
    ) {
    }

    public function hasChanges(): bool
    {
        return $this->created > 0 || $this->resolved > 0;
    }
}
