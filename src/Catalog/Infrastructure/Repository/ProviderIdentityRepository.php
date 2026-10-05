<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Repository;

use App\Catalog\Domain\Entity\ProviderIdentity;
use App\Catalog\Domain\Enum\ProviderIdentityType;
use App\Catalog\Domain\Repository\ProviderIdentityRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class ProviderIdentityRepository extends DoctrineRepository implements ProviderIdentityRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function findByTypeAndValue(ProviderIdentityType $type, string $value): ?ProviderIdentity
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('i')
            ->from(ProviderIdentity::class, 'i')
            ->where('i.type = :type')
            ->andWhere('i.value = :value')
            ->setParameter('type', $type)
            ->setParameter('value', mb_strtolower(trim($value)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForProvider(Uuid $providerId): array
    {
        /** @var list<ProviderIdentity> $identities */
        $identities = $this->entityManager
            ->createQueryBuilder()
            ->select('i')
            ->from(ProviderIdentity::class, 'i')
            ->where('IDENTITY(i.provider) = :providerId')
            ->setParameter('providerId', $providerId)
            ->orderBy('i.type', 'ASC')
            ->addOrderBy('i.value', 'ASC')
            ->getQuery()
            ->getResult();

        return $identities;
    }

    public function findByType(ProviderIdentityType $type): array
    {
        /** @var list<ProviderIdentity> $identities */
        $identities = $this->entityManager
            ->createQueryBuilder()
            ->select('i')
            ->from(ProviderIdentity::class, 'i')
            ->where('i.type = :type')
            ->setParameter('type', $type)
            ->orderBy('i.confidence', 'DESC')
            ->getQuery()
            ->getResult();

        return $identities;
    }

    public function save(ProviderIdentity $identity, bool $flush = true): void
    {
        $this->persist($identity, $flush);
    }
}
