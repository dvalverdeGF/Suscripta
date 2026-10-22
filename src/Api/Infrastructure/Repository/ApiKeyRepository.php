<?php

declare(strict_types=1);

namespace App\Api\Infrastructure\Repository;

use App\Api\Domain\Entity\ApiKey;
use App\Api\Domain\Repository\ApiKeyRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class ApiKeyRepository extends DoctrineRepository implements ApiKeyRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?ApiKey
    {
        return $this->entityManager->find(ApiKey::class, $id);
    }

    public function findBySecretHash(string $secretHash): ?ApiKey
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('k')
            ->from(ApiKey::class, 'k')
            ->where('k.secretHash = :hash')
            ->setParameter('hash', $secretHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForOrganization(Uuid $organizationId): array
    {
        /** @var list<ApiKey> $apiKeys */
        $apiKeys = $this->entityManager
            ->createQueryBuilder()
            ->select('k')
            ->from(ApiKey::class, 'k')
            ->where('k.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->orderBy('k.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $apiKeys;
    }

    public function save(ApiKey $apiKey, bool $flush = true): void
    {
        $this->persist($apiKey, $flush);
    }

    public function remove(ApiKey $apiKey, bool $flush = true): void
    {
        $this->delete($apiKey, $flush);
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->delete(ApiKey::class, 'k')
            ->where('k.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->getQuery()
            ->execute();
    }
}
