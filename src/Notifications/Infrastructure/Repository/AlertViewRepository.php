<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Repository;

use App\Notifications\Domain\Dto\AlertView;
use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertStatus;
use App\Notifications\Domain\Repository\AlertViewRepositoryInterface;
use App\Shared\Application\Page;
use App\Shared\Application\Pagination;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Doctrine\ORM\QueryBuilder;

use function mb_strtolower;
use function trim;

/**
 * Implementación Doctrine del read model de alertas.
 *
 * La conversión entidad → vista ocurre aquí, en la frontera del módulo: a
 * partir de este punto nadie fuera de `Notifications` ve la entidad `Alert`.
 */
final class AlertViewRepository extends DoctrineRepository implements AlertViewRepositoryInterface
{
    public function findPage(
        ?AlertStatus $status = null,
        ?AlertSeverity $severity = null,
        ?string $search = null,
        ?string $sort = null,
        int $page = 1,
        int $perPage = 25,
    ): Page {
        $pagination = Pagination::fromRequest($page, $perPage);

        $qb = $this->createQueryBuilder()
            ->select('a')
            ->from(Alert::class, 'a');

        if (null !== $status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status->value);
        }

        if (null !== $severity) {
            $qb->andWhere('a.severity = :severity')->setParameter('severity', $severity->value);
        }

        if (null !== $search && '' !== trim($search)) {
            $qb->andWhere('LOWER(a.title) LIKE :search OR LOWER(a.message) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower(trim($search)).'%');
        }

        $total = (int) $this->countMatching($qb);

        $this->applySort($qb, $sort);

        $qb->setFirstResult($pagination->getOffset())
            ->setMaxResults($pagination->perPage);

        /** @var list<Alert> $alerts */
        $alerts = $qb->getQuery()->getResult();

        $views = [];
        foreach ($alerts as $alert) {
            $views[] = AlertView::fromAlert($alert);
        }

        return Page::of($views, $pagination->withTotal($total));
    }

    private function createQueryBuilder(): QueryBuilder
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('a')
            ->from(Alert::class, 'a');
    }

    private function countMatching(QueryBuilder $qb): int
    {
        $countQb = clone $qb;
        $countQb->resetDQLPart('orderBy')
            ->setFirstResult(null)
            ->setMaxResults(null)
            ->select('COUNT(a.id)');

        return (int) $countQb->getQuery()->getSingleScalarResult();
    }

    private function applySort(QueryBuilder $qb, ?string $sort): void
    {
        match ($sort) {
            'due_at' => $qb->orderBy('a.dueAt', 'ASC')->addOrderBy('a.createdAt', 'DESC'),
            'severity' => $qb
                ->addSelect('CASE a.severity WHEN \'critical\' THEN 3 WHEN \'warning\' THEN 2 ELSE 1 END AS HIDDEN severity_rank')
                ->orderBy('severity_rank', 'DESC')
                ->addOrderBy('a.createdAt', 'DESC'),
            'oldest' => $qb->orderBy('a.createdAt', 'ASC'),
            default => $qb->orderBy('a.createdAt', 'DESC'),
        };
    }
}
