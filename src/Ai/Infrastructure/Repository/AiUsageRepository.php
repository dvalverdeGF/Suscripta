<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure\Repository;

use App\Ai\Domain\Entity\AiUsage;
use App\Ai\Domain\Repository\AiUsageRepositoryInterface;
use App\Shared\Domain\ValueObject\DateRange;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use BackedEnum;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Acceso al historial de uso de IA.
 *
 * Las agregaciones se hacen en SQL y no en PHP: el histórico puede tener
 * cientos de miles de filas y traerlas todas para sumarlas en memoria es
 * exactamente el tipo de operación que hace que un panel de coste se caiga
 * justo cuando el cliente está mirando.
 */
final class AiUsageRepository extends DoctrineRepository implements AiUsageRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function save(AiUsage $usage, bool $flush = true): void
    {
        $this->persist($usage, $flush);
    }

    public function totalCostMinor(Uuid $organizationId, DateRange $period): int
    {
        $result = $this->sumQuery($organizationId, $period)
            ->select('COALESCE(SUM(u.costMinor), 0)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    public function costByProvider(Uuid $organizationId, DateRange $period): array
    {
        /** @var list<array{providerKey: string, total: int|string}> $rows */
        $rows = $this->sumQuery($organizationId, $period)
            ->select('u.providerKey AS providerKey', 'SUM(u.costMinor) AS total')
            ->groupBy('u.providerKey')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getResult();

        $costs = [];

        foreach ($rows as $row) {
            $costs[$row['providerKey']] = (int) $row['total'];
        }

        return $costs;
    }

    public function costByPurpose(Uuid $organizationId, DateRange $period): array
    {
        /** @var list<array{purpose: string, total: int|string}> $rows */
        $rows = $this->sumQuery($organizationId, $period)
            ->select('u.purpose AS purpose', 'SUM(u.costMinor) AS total')
            ->groupBy('u.purpose')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getResult();

        $costs = [];

        foreach ($rows as $row) {
            $costs[$this->enumValue($row['purpose'])] = (int) $row['total'];
        }

        return $costs;
    }

    public function usageSummary(Uuid $organizationId, DateRange $period): array
    {
        /** @var array{calls: int|string, promptTokens: int|string, completionTokens: int|string, failures: int|string}|null $row */
        $row = $this->sumQuery($organizationId, $period)
            ->select(
                'COUNT(u.id) AS calls',
                'COALESCE(SUM(u.promptTokens), 0) AS promptTokens',
                'COALESCE(SUM(u.completionTokens), 0) AS completionTokens',
                'COALESCE(SUM(CASE WHEN u.successful = false THEN 1 ELSE 0 END), 0) AS failures',
            )
            ->getQuery()
            ->getSingleResult();

        return [
            'calls' => (int) ($row['calls'] ?? 0),
            'promptTokens' => (int) ($row['promptTokens'] ?? 0),
            'completionTokens' => (int) ($row['completionTokens'] ?? 0),
            'failures' => (int) ($row['failures'] ?? 0),
        ];
    }

    public function recent(Uuid $organizationId, int $limit = 50): array
    {
        /** @var list<AiUsage> $usages */
        $usages = $this->entityManager
            ->createQueryBuilder()
            ->select('u')
            ->from(AiUsage::class, 'u')
            ->where('u.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->orderBy('u.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $usages;
    }

    /**
     * Duración media de las llamadas, en milisegundos.
     */
    public function averageDurationMs(Uuid $organizationId, DateRange $period): int
    {
        $result = $this->sumQuery($organizationId, $period)
            ->select('COALESCE(AVG(u.durationMs), 0)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) round((float) $result);
    }

    /**
     * Número de llamadas por nivel de IA.
     *
     * @return array<string, int>
     */
    public function callsByTier(Uuid $organizationId, DateRange $period): array
    {
        /** @var list<array{tier: string, total: int|string}> $rows */
        $rows = $this->sumQuery($organizationId, $period)
            ->select('u.tier AS tier', 'COUNT(u.id) AS total')
            ->groupBy('u.tier')
            ->getQuery()
            ->getResult();

        $calls = [];

        foreach ($rows as $row) {
            $calls[$this->enumValue($row['tier'])] = (int) $row['total'];
        }

        return $calls;
    }

    /**
     * Duración media de las llamadas por nivel de IA.
     *
     * @return array<string, int>
     */
    public function durationByTier(Uuid $organizationId, DateRange $period): array
    {
        /** @var list<array{tier: string, total: int|string}> $rows */
        $rows = $this->sumQuery($organizationId, $period)
            ->select('u.tier AS tier', 'COALESCE(AVG(u.durationMs), 0) AS total')
            ->groupBy('u.tier')
            ->getQuery()
            ->getResult();

        $durations = [];

        foreach ($rows as $row) {
            $durations[$this->enumValue($row['tier'])] = (int) round((float) $row['total']);
        }

        return $durations;
    }

    /**
     * Normaliza una columna mapeada como enum a su valor de texto.
     *
     * Las columnas `purpose` y `tier` están mapeadas con `enumType`, así que
     * Doctrine devuelve el **objeto enum** en las consultas de agregación, no
     * su valor. Indexar el array por el objeto es imposible (no es un string)
     * y, además, la vista busca las claves por valor (`extraction`, `cheap`).
     */
    private function enumValue(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }

    /**
     * Consulta base con la organización y el rango ya aplicados.
     */
    private function sumQuery(Uuid $organizationId, DateRange $period): QueryBuilder
    {
        $qb = $this->entityManager
            ->createQueryBuilder()
            ->from(AiUsage::class, 'u')
            ->where('u.organizationId = :organizationId')
            ->andWhere('u.createdAt >= :from')
            ->setParameter('organizationId', $organizationId)
            ->setParameter('from', $period->from);

        if (null !== $period->to) {
            $qb->andWhere('u.createdAt < :to')->setParameter('to', $period->to);
        }

        return $qb;
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->delete(AiUsage::class, 'u')
            ->where('u.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->getQuery()
            ->execute();
    }

    public function purgeOlderThan(DateTimeImmutable $threshold, int $limit = 1000): int
    {
        $ids = $this->scalarIds($this->entityManager
            ->createQueryBuilder()
            ->select('u.id')
            ->from(AiUsage::class, 'u')
            ->where('u.createdAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult());

        if ([] === $ids) {
            return 0;
        }

        return (int) $this->entityManager
            ->createQueryBuilder()
            ->delete(AiUsage::class, 'u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    public function findForOrganization(Uuid $organizationId): array
    {
        /** @var list<AiUsage> $usages */
        $usages = $this->entityManager
            ->createQueryBuilder()
            ->select('u')
            ->from(AiUsage::class, 'u')
            ->where('u.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->orderBy('u.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $usages;
    }
}
