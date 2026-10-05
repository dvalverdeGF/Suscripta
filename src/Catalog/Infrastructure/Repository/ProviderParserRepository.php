<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Repository;

use App\Catalog\Domain\Entity\ProviderParser;
use App\Catalog\Domain\Repository\ProviderParserRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class ProviderParserRepository extends DoctrineRepository implements ProviderParserRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?ProviderParser
    {
        return $this->entityManager->find(ProviderParser::class, $id);
    }

    public function findEnabledForProvider(Uuid $providerId): array
    {
        /** @var list<ProviderParser> $parsers */
        $parsers = $this->entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(ProviderParser::class, 'p')
            ->where('IDENTITY(p.provider) = :providerId')
            ->andWhere('p.enabled = true')
            ->setParameter('providerId', $providerId)
            ->orderBy('p.version', 'DESC')
            ->getQuery()
            ->getResult();

        return $parsers;
    }

    public function findForProvider(Uuid $providerId): array
    {
        /** @var list<ProviderParser> $parsers */
        $parsers = $this->entityManager
            ->createQueryBuilder()
            ->select('p')
            ->from(ProviderParser::class, 'p')
            ->where('IDENTITY(p.provider) = :providerId')
            ->setParameter('providerId', $providerId)
            ->orderBy('p.version', 'DESC')
            ->getQuery()
            ->getResult();

        return $parsers;
    }

    public function save(ProviderParser $parser, bool $flush = true): void
    {
        $this->persist($parser, $flush);
    }
}
