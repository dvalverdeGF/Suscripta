<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class UserRepository extends DoctrineRepository implements UserRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?User
    {
        return $this->entityManager->find(User::class, $id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function emailExists(string $email): bool
    {
        $count = $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function save(User $user, bool $flush = true): void
    {
        $this->persist($user, $flush);
    }
}
