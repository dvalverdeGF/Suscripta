<?php

declare(strict_types=1);

namespace App\Discovery\Application\Change;

use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryType;

use function count;

/**
 * Resultado de un barrido de detección de cambios.
 *
 * No es solo un contador: el comando y la interfaz necesitan saber **qué** se
 * ha propuesto, para poder explicarlo y para poder repetir el barrido sin
 * duplicar nada (las propuestas que ya existían no se vuelven a crear).
 */
final readonly class ChangeDetectionResult
{
    /**
     * @param list<Discovery>    $proposals         propuestas creadas en este barrido
     * @param array<string, int> $countsByType      tipo => número de propuestas creadas
     * @param int                $servicesInspected servicios mirados
     */
    public function __construct(
        public array $proposals = [],
        public array $countsByType = [],
        public int $servicesInspected = 0,
    ) {
    }

    public function total(): int
    {
        return count($this->proposals);
    }

    public function countOf(DiscoveryType $type): int
    {
        return $this->countsByType[$type->value] ?? 0;
    }

    public function isEmpty(): bool
    {
        return [] === $this->proposals;
    }
}
