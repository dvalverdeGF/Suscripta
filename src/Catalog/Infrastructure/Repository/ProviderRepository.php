<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Repository;

use App\Catalog\Domain\Entity\Provider;
use App\Catalog\Domain\Repository\ProviderRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `Provider` no implementa TenantAwareInterface porque el catálogo global tiene
 * `organization_id = NULL`. El filtro se aplica aquí de forma explícita y
 * siempre: global + propio del tenant, nunca el de otra organización.
 */
final class ProviderRepository extends DoctrineRepository implements ProviderRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?Provider
    {
        return $this->entityManager->find(Provider::class, $id);
    }

    public function findVisibleForOrganization(Uuid $organizationId): array
    {
        /** @var list<Provider> $providers */
        $providers = $this->entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(Provider::class, 'p')
            ->where('p.organizationId IS NULL OR p.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $providers;
    }

    public function findVisibleIndexedById(Uuid $organizationId): array
    {
        $indexed = [];

        foreach ($this->findVisibleForOrganization($organizationId) as $provider) {
            $indexed[$provider->getId()->toRfc4122()] = $provider;
        }

        return $indexed;
    }

    public function findBySlug(Uuid $organizationId, string $slug): ?Provider
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(Provider::class, 'p')
            ->where('p.slug = :slug')
            ->andWhere('p.organizationId IS NULL OR p.organizationId = :organizationId')
            ->setParameter('slug', $slug)
            ->setParameter('organizationId', $organizationId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findGlobalBySlug(string $slug): ?Provider
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(Provider::class, 'p')
            ->where('p.slug = :slug')
            ->andWhere('p.organizationId IS NULL')
            ->setParameter('slug', $slug)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(Provider $provider, bool $flush = true): void
    {
        $this->persist($provider, $flush);
    }
}
