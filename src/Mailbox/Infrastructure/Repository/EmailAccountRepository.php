<?php

declare(strict_types=1);

namespace App\Mailbox\Infrastructure\Repository;

use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Enum\EmailAccountStatus;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class EmailAccountRepository extends DoctrineRepository implements EmailAccountRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?EmailAccount
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('a')
            ->from(EmailAccount::class, 'a')
            ->where('a.id = :id')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForOrganization(): array
    {
        /** @var list<EmailAccount> $accounts */
        $accounts = $this->entityManager
            ->createQueryBuilder()
            ->select('a')
            ->from(EmailAccount::class, 'a')
            ->where('a.deletedAt IS NULL')
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $accounts;
    }

    public function findSyncable(): array
    {
        /** @var list<EmailAccount> $accounts */
        $accounts = $this->entityManager
            ->createQueryBuilder()
            ->select('a')
            ->from(EmailAccount::class, 'a')
            ->where('a.deletedAt IS NULL')
            ->andWhere('a.status = :status')
            ->setParameter('status', EmailAccountStatus::ACTIVE)
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $accounts;
    }

    public function findByAddress(string $emailAddress): ?EmailAccount
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('a')
            ->from(EmailAccount::class, 'a')
            ->where('a.emailAddress = :address')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('address', mb_strtolower(trim($emailAddress)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByForwardingAddress(string $address): ?EmailAccount
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('a')
            ->from(EmailAccount::class, 'a')
            ->where('a.forwardingAddress = :address')
            ->andWhere('a.forwardingEnabled = true')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('address', mb_strtolower(trim($address)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countForOrganization(): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(EmailAccount::class, 'a')
            ->where('a.deletedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(EmailAccount $account, bool $flush = true): void
    {
        $this->persist($account, $flush);
    }

    public function remove(EmailAccount $account, bool $flush = true): void
    {
        $this->delete($account, $flush);
    }
}
