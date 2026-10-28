<?php

declare(strict_types=1);

namespace App\Mailbox\Infrastructure\Repository;

use App\Mailbox\Domain\Dto\EmailAccountView;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Repository\EmailAccountViewRepositoryInterface;
use App\Shared\Application\Page;
use App\Shared\Application\Pagination;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Implementación Doctrine del read model de cuentas de correo.
 */
final class EmailAccountViewRepository extends DoctrineRepository implements EmailAccountViewRepositoryInterface
{
    /**
     * @return Page<EmailAccountView>
     */
    public function findPage(int $page = 1, int $perPage = 50): Page
    {
        $pagination = Pagination::fromRequest($page, $perPage);

        $qb = $this->entityManager
            ->createQueryBuilder()
            ->select('a')
            ->from(EmailAccount::class, 'a')
            ->where('a.deletedAt IS NULL');

        $countQb = clone $qb;
        $countQb->resetDQLPart('orderBy')
            ->setFirstResult(null)
            ->setMaxResults(null)
            ->select('COUNT(a.id)');

        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $qb->orderBy('a.createdAt', 'ASC')
            ->setFirstResult($pagination->getOffset())
            ->setMaxResults($pagination->perPage);

        /** @var list<EmailAccount> $accounts */
        $accounts = $qb->getQuery()->getResult();

        $views = [];
        foreach ($accounts as $account) {
            $views[] = EmailAccountView::fromAccount($account);
        }

        return Page::of($views, $pagination->withTotal($total));
    }

    public function findOne(Uuid $id): ?EmailAccountView
    {
        // Consulta DQL y no `EntityManager::find()`: los filtros SQL de
        // Doctrine solo se aplican a las consultas DQL, y un `find()` traería
        // una cuenta de otra organización si se conoce su identificador.
        $account = $this->entityManager
            ->createQueryBuilder()
            ->select('a')
            ->from(EmailAccount::class, 'a')
            ->where('a.id = :id')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $account instanceof EmailAccount ? EmailAccountView::fromAccount($account) : null;
    }
}
