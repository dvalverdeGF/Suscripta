<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\Entity\PasswordResetRequest;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\PasswordResetRequestRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final class PasswordResetRequestRepository extends DoctrineRepository implements PasswordResetRequestRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function findByTokenHash(string $tokenHash): ?PasswordResetRequest
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('r')
            ->from(PasswordResetRequest::class, 'r')
            ->where('r.tokenHash = :hash')
            ->setParameter('hash', $tokenHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function invalidatePendingFor(User $user, DateTimeImmutable $at): void
    {
        $this->entityManager
            ->createQueryBuilder()
            ->update(PasswordResetRequest::class, 'r')
            ->set('r.usedAt', ':at')
            ->where('r.user = :user')
            ->andWhere('r.usedAt IS NULL')
            ->setParameter('at', $at)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    public function save(PasswordResetRequest $request, bool $flush = true): void
    {
        $this->persist($request, $flush);
    }
}
