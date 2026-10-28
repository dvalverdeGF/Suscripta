<?php

declare(strict_types=1);

namespace App\Documents\Infrastructure\Repository;

use App\Documents\Domain\Dto\DocumentView;
use App\Documents\Domain\Entity\Document;
use App\Documents\Domain\Enum\DocumentType;
use App\Documents\Domain\Repository\DocumentViewRepositoryInterface;
use App\Shared\Application\Page;
use App\Shared\Application\Pagination;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Implementación Doctrine del read model de documentos.
 */
final class DocumentViewRepository extends DoctrineRepository implements DocumentViewRepositoryInterface
{
    public function findPage(
        ?DocumentType $type = null,
        ?Uuid $serviceId = null,
        ?bool $includeDeleted = false,
        int $page = 1,
        int $perPage = 25,
    ): Page {
        $pagination = Pagination::fromRequest($page, $perPage);

        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('d')
            ->from(Document::class, 'd');

        if (null !== $type) {
            $qb->andWhere('d.type = :type')->setParameter('type', $type->value);
        }

        if (null !== $serviceId) {
            $qb->andWhere('d.serviceId = :serviceId')->setParameter('serviceId', $serviceId);
        }

        if (true !== $includeDeleted) {
            $qb->andWhere('d.deletedAt IS NULL');
        }

        $countQb = clone $qb;
        $countQb->resetDQLPart('orderBy')
            ->setFirstResult(null)
            ->setMaxResults(null)
            ->select('COUNT(d.id)');

        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $qb->orderBy('d.createdAt', 'DESC')
            ->setFirstResult($pagination->getOffset())
            ->setMaxResults($pagination->perPage);

        /** @var list<Document> $documents */
        $documents = $qb->getQuery()->getResult();

        $views = [];
        foreach ($documents as $document) {
            $views[] = DocumentView::fromDocument($document);
        }

        return Page::of($views, $pagination->withTotal($total));
    }

    public function findOne(Uuid $id): ?DocumentView
    {
        // Consulta DQL y no `EntityManager::find()`: los filtros SQL de
        // Doctrine solo se aplican a las consultas DQL, y un `find()` traería
        // un documento de otra organización si se conoce su identificador.
        $document = $this->entityManager
            ->createQueryBuilder()
            ->select('d')
            ->from(Document::class, 'd')
            ->where('d.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $document instanceof Document ? DocumentView::fromDocument($document) : null;
    }
}
