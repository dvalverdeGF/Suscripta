<?php

declare(strict_types=1);

namespace App\Ai\Domain\Repository;

use App\Ai\Domain\Entity\AiUsage;
use App\Shared\Domain\ValueObject\DateRange;
use Symfony\Component\Uid\Uuid;

/**
 * Historial de uso de IA.
 *
 * El método de agregación es el que usa el panel de coste: no hace falta
 * traer miles de filas para saber cuánto se ha gastado, lo que además evita
 * cargar en memoria la traza completa de una organización grande.
 */
interface AiUsageRepositoryInterface
{
    public function save(AiUsage $usage, bool $flush = true): void;

    /**
     * Coste total en céntimos en un rango, para una organización.
     */
    public function totalCostMinor(Uuid $organizationId, DateRange $period): int;

    /**
     * Coste desglosado por proveedor.
     *
     * @return array<string, int> clave de proveedor => coste en céntimos
     */
    public function costByProvider(Uuid $organizationId, DateRange $period): array;

    /**
     * Coste desglosado por necesidad.
     *
     * @return array<string, int> valor de la necesidad => coste en céntimos
     */
    public function costByPurpose(Uuid $organizationId, DateRange $period): array;

    /**
     * Número de llamadas y desglose de tokens en un rango.
     *
     * @return array{calls: int, promptTokens: int, completionTokens: int, failures: int}
     */
    public function usageSummary(Uuid $organizationId, DateRange $period): array;

    /**
     * Últimos usos registrados, para la traza del backoffice.
     *
     * @return list<AiUsage>
     */
    public function recent(Uuid $organizationId, int $limit = 50): array;

    /**
     * Duración media de las llamadas, en milisegundos.
     */
    public function averageDurationMs(Uuid $organizationId, DateRange $period): int;

    /**
     * Número de llamadas por nivel de IA.
     *
     * @return array<string, int> valor del nivel => número de llamadas
     */
    public function callsByTier(Uuid $organizationId, DateRange $period): array;

    /**
     * Duración media de las llamadas por nivel de IA, en milisegundos.
     *
     * @return array<string, int> valor del nivel => duración media
     */
    public function durationByTier(Uuid $organizationId, DateRange $period): array;
}
