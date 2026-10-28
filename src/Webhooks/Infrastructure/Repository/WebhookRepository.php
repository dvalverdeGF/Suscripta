<?php

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Repository;

use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use App\Webhooks\Domain\Entity\Webhook;
use App\Webhooks\Domain\Enum\WebhookEvent;
use App\Webhooks\Domain\Repository\WebhookRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class WebhookRepository extends DoctrineRepository implements WebhookRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?Webhook
    {
        return $this->entityManager->find(Webhook::class, $id);
    }

    public function findForOrganization(Uuid $organizationId): array
    {
        /** @var list<Webhook> $webhooks */
        $webhooks = $this->entityManager
            ->createQueryBuilder()
            ->select('w')
            ->from(Webhook::class, 'w')
            ->where('w.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->orderBy('w.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $webhooks;
    }

    /**
     * El filtro por evento se hace en PHP y no en SQL.
     *
     * Los eventos se guardan como JSON (`["proposal.confirmed", …]`), y
     * consultarlos con `LIKE` daría falsos positivos: `proposal.created` es
     * prefijo textual de nada, pero `charge.detected` podría aparecer dentro de
     * un nombre más largo en el futuro. La lista de suscriptores activos de una
     * organización es corta (decenas como mucho), así que filtrar en memoria
     * cuesta nada y no puede equivocarse.
     *
     * @return list<Webhook>
     */
    public function findActiveSubscribers(Uuid $organizationId, WebhookEvent $event): array
    {
        return array_values(array_filter(
            $this->findForOrganization($organizationId),
            static fn (Webhook $webhook): bool => $webhook->isActive() && $webhook->isSubscribedTo($event),
        ));
    }

    public function save(Webhook $webhook, bool $flush = true): void
    {
        $this->persist($webhook, $flush);
    }

    public function remove(Webhook $webhook, bool $flush = true): void
    {
        $this->delete($webhook, $flush);
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->delete(Webhook::class, 'w')
            ->where('w.organizationId = :organizationId')
            ->setParameter('organizationId', $organizationId)
            ->getQuery()
            ->execute();
    }
}
