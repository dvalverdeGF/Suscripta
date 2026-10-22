<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure\Repository;

use App\Ai\Domain\Entity\AiBudget;
use App\Ai\Domain\Repository\AiBudgetRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Acceso al presupuesto de IA.
 *
 * La búsqueda es por organización **y** periodo, que es la clave única de la
 * tabla: hay como mucho un presupuesto por mes y por cliente.
 */
final class AiBudgetRepository extends DoctrineRepository implements AiBudgetRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $organizationId, string $period): ?AiBudget
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('b')
            ->from(AiBudget::class, 'b')
            ->where('b.organizationId = :organizationId')
            ->andWhere('b.period = :period')
            ->setParameter('organizationId', $organizationId)
            ->setParameter('period', $period)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(AiBudget $budget, bool $flush = true): void
    {
        $this->persist($budget, $flush);
    }

    public function flush(): void
    {
        parent::flush();
    }
}
