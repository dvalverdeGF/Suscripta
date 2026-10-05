<?php

declare(strict_types=1);

namespace App\Processing\Domain\Repository;

use App\Processing\Domain\Entity\ExtractionCache;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

interface ExtractionCacheRepositoryInterface
{
    /**
     * Busca una extracción ya calculada para este contenido dentro de la
     * organización activa. Es la consulta que evita volver a analizar (y, más
     * adelante, volver a pagar IA) por un correo repetido.
     */
    public function findForContentHash(string $contentHash): ?ExtractionCache;

    public function save(ExtractionCache $cache, bool $flush = true): void;

    /**
     * Borra la caché de una organización. Se usa al eliminar una organización
     * o al purgar por retención (SECURITY.md §6).
     */
    public function removeForOrganization(Uuid $organizationId): int;

    /**
     * Cuántas extracciones guarda la organización activa. Es la medida del
     * ahorro acumulado: cada entrada es un análisis que no se repetirá.
     */
    public function countForOrganization(): int;

    /**
     * Entradas usadas por última vez antes de la fecha indicada. Permite podar
     * la caché sin perder lo que se sigue usando (Fase 13).
     *
     * @return list<ExtractionCache>
     */
    public function findStaleBefore(DateTimeImmutable $threshold, int $limit = 500): array;
}
