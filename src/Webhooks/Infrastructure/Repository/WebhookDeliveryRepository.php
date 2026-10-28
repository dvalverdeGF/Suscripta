<?php

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Repository;

use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use App\Webhooks\Domain\Entity\WebhookDelivery;
use App\Webhooks\Domain\Enum\DeliveryStatus;
use App\Webhooks\Domain\Repository\WebhookDeliveryRepositoryInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function sprintf;

use Symfony\Component\Uid\Uuid;

final class WebhookDeliveryRepository extends DoctrineRepository implements WebhookDeliveryRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function find(Uuid $id): ?WebhookDelivery
    {
        return $this->entityManager->find(WebhookDelivery::class, $id);
    }

    public function findForWebhook(Uuid $webhookId, int $limit = 50): array
    {
        /** @var list<WebhookDelivery> $deliveries */
        $deliveries = $this->entityManager
            ->createQueryBuilder()
            ->select('d')
            ->from(WebhookDelivery::class, 'd')
            ->where('d.webhookId = :webhookId')
            ->setParameter('webhookId', $webhookId)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $deliveries;
    }

    /**
     * Las entregas vencidas se buscan **sin** filtro de organización.
     *
     * Las ejecuta el worker, que no tiene una organización activa: si se
     * filtrara por la del mensaje, una entrega de otra organización quedaría
     * fuera del lote y el reintento se retrasaría hasta que le tocara el turno.
     */
    public function findDue(int $limit = 100): array
    {
        /** @var list<WebhookDelivery> $deliveries */
        $deliveries = $this->entityManager
            ->createQueryBuilder()
            ->select('d')
            ->from(WebhookDelivery::class, 'd')
            ->where('d.status = :status')
            ->andWhere('d.nextAttemptAt <= :now')
            ->setParameter('status', DeliveryStatus::PENDING)
            ->setParameter('now', new DateTimeImmutable())
            ->orderBy('d.nextAttemptAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $deliveries;
    }

    public function findAbandonable(int $days, int $limit = 500): array
    {
        $threshold = new DateTimeImmutable(sprintf('-%d days', $days));

        /** @var list<WebhookDelivery> $deliveries */
        $deliveries = $this->entityManager
            ->createQueryBuilder()
            ->select('d')
            ->from(WebhookDelivery::class, 'd')
            ->where('d.status = :status')
            ->andWhere('d.createdAt <= :threshold')
            ->setParameter('status', DeliveryStatus::PENDING)
            ->setParameter('threshold', $threshold)
            ->orderBy('d.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $deliveries;
    }

    public function countPending(Uuid $webhookId): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(WebhookDelivery::class, 'd')
            ->where('d.webhookId = :webhookId')
            ->andWhere('d.status = :status')
            ->setParameter('webhookId', $webhookId)
            ->setParameter('status', DeliveryStatus::PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(WebhookDelivery $delivery, bool $flush = true): void
    {
        $this->persist($delivery, $flush);
    }

    public function removeForWebhook(Uuid $webhookId): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->delete(WebhookDelivery::class, 'd')
            ->where('d.webhookId = :webhookId')
            ->setParameter('webhookId', $webhookId)
            ->getQuery()
            ->execute();
    }
}
