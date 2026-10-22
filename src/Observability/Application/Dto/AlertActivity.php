<?php

declare(strict_types=1);

namespace App\Observability\Application\Dto;

/**
 * Actividad de avisos: cuántas alertas hay abiertas y de qué tipo.
 *
 * Se cuenta por tipo porque "hay 12 alertas" no dice nada accionable, pero
 * "hay 4 de presupuesto de IA superada" sí.
 */
final readonly class AlertActivity
{
    /**
     * @param array<string, int> $byStatus   alertas por estado
     * @param array<string, int> $openByType alertas abiertas por tipo
     */
    public function __construct(
        public int $open,
        public array $byStatus,
        public array $openByType,
    ) {
    }

    public function hasOpen(): bool
    {
        return $this->open > 0;
    }
}
