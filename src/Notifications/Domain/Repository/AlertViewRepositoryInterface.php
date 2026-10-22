<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Repository;

use App\Notifications\Domain\Dto\AlertView;
use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertStatus;
use App\Shared\Application\Page;

/**
 * Repositorio de **lectura** de alertas.
 *
 * Separado del `AlertRepositoryInterface` por el mismo motivo que en
 * `Services` (ARCHITECTURE.md §3): la entidad `Alert` es de escritura, y
 * exponerla a la API obligaría a cada consumidor a conocer sus enums y a
 * duplicar la traducción.
 */
interface AlertViewRepositoryInterface
{
    /**
     * Página de alertas con los filtros indicados.
     *
     * Los filtros se aplican **en la consulta**, no después: la bandeja de
     * alertas puede tener miles de filas y filtrar en memoria traería
     * justo lo que el consumidor pidió evitar.
     *
     * @return Page<AlertView>
     */
    public function findPage(
        ?AlertStatus $status = null,
        ?AlertSeverity $severity = null,
        ?string $search = null,
        ?string $sort = null,
        int $page = 1,
        int $perPage = 25,
    ): Page;
}
