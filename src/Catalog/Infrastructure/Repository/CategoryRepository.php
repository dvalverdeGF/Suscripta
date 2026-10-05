<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Repository;

use App\Catalog\Domain\Entity\Category;
use App\Catalog\Domain\Repository\CategoryRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class CategoryRepository extends DoctrineRepository implements CategoryRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?Category
    {
        return $this->entityManager->find(Category::class, $id);
    }

    public function findVisibleForOrganization(Uuid $organizationId): array
    {
        /** @var list<Category> $categories */
        $categories = $this->entityManager
            ->createQueryBuilder()
            ->select('c')
            ->from(Category::class, 'c')
            ->where('c.organizationId IS NULL OR c.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->orderBy('c.sortOrder', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $categories;
    }

    public function findVisibleIndexedById(Uuid $organizationId): array
    {
        $indexed = [];

        foreach ($this->findVisibleForOrganization($organizationId) as $category) {
            $indexed[$category->getId()->toRfc4122()] = $category;
        }

        return $indexed;
    }

    public function findGlobalBySlug(string $slug): ?Category
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('c')
            ->from(Category::class, 'c')
            ->where('c.slug = :slug')
            ->andWhere('c.organizationId IS NULL')
            ->setParameter('slug', $slug)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(Category $category, bool $flush = true): void
    {
        $this->persist($category, $flush);
    }
}
