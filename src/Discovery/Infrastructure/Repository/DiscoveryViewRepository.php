<?php

declare(strict_types=1);

namespace App\Discovery\Infrastructure\Repository;

use App\Discovery\Domain\Dto\DiscoveryView;
use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryStatus;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryViewRepositoryInterface;
use App\Shared\Application\Page;
use App\Shared\Application\Pagination;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Implementación Doctrine del read model de propuestas.
 */
final class DiscoveryViewRepository extends DoctrineRepository implements DiscoveryViewRepositoryInterface
{
    public function findPage(
        ?DiscoveryStatus $status = null,
        ?DiscoveryType $type = null,
        int $page = 1,
        int $perPage = 25,
    ): Page {
        $pagination = Pagination::fromRequest($page, $perPage);

        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('d')
            ->from(Discovery::class, 'd');

        if (null !== $status) {
            $qb->andWhere('d.status = :status')->setParameter('status', $status->value);
        }

        if (null !== $type) {
            $qb->andWhere('d.type = :type')->setParameter('type', $type->value);
        }

        $countQb = clone $qb;
        $countQb->resetDQLPart('orderBy')
            ->setFirstResult(null)
            ->setMaxResults(null)
            ->select('COUNT(d.id)');

        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $qb->orderBy('d.detectedAt', 'DESC')
            ->setFirstResult($pagination->getOffset())
            ->setMaxResults($pagination->perPage);

        /** @var list<Discovery> $discoveries */
        $discoveries = $qb->getQuery()->getResult();

        $views = [];
        foreach ($discoveries as $discovery) {
            $views[] = DiscoveryView::fromDiscovery($discovery);
        }

        return Page::of($views, $pagination->withTotal($total));
    }

    public function findOne(Uuid $id): ?DiscoveryView
    {
        // Consulta DQL y no `EntityManager::find()`: los filtros SQL de
        // Doctrine solo se aplican a las consultas DQL, y un `find()` traería
        // una propuesta de otra organización si se conoce su identificador.
        $discovery = $this->entityManager
            ->createQueryBuilder()
            ->select('d')
            ->from(Discovery::class, 'd')
            ->where('d.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $discovery instanceof Discovery ? DiscoveryView::fromDiscovery($discovery) : null;
    }
}
