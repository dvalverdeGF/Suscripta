<?php

declare(strict_types=1);

namespace App\Ai\Domain\Repository;

use App\Ai\Domain\Entity\AiBudget;
use Symfony\Component\Uid\Uuid;

/**
 * El presupuesto de IA por organización y periodo (Fase 14).
 *
 * El patrón es el de una fila por combinación organización + periodo: el
 * presupuesto de marzo y el de abril son dos filas distintas, no una fila con
 * dos columnas.
 */
interface AiBudgetRepositoryInterface
{
    /**
     * El presupuesto de una organización para un periodo (`YYYY-MM`).
     */
    public function find(Uuid $organizationId, string $period): ?AiBudget;

    public function save(AiBudget $budget, bool $flush = true): void;

    public function flush(): void;
}
