<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Ai\Domain\Entity\AiUsage;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Ai\Infrastructure\Repository\AiUsageRepository;
use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Entity\DiscoveryEvidence;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Infrastructure\Repository\DiscoveryRepository;
use App\Mailbox\Domain\Entity\MessageProcessingEvent;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Mailbox\Infrastructure\Repository\MessageProcessingEventRepository;
use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Infrastructure\Repository\AlertRepository;
use App\Shared\Domain\Entity\AuditLog;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Infrastructure\Repository\AuditLogRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Las purgas de retención (Fase 16) se escriben en dos pasos: se seleccionan los
 * identificadores que cumplen el criterio de antigüedad y se borran con un
 * `DELETE ... WHERE id IN (:ids)`.
 *
 * El segundo paso es el que se rompe en silencio: si la lista de identificadores
 * no llega al `IN` con el formato correcto, la consulta borra cero filas y la
 * política de retención deja de purgar sin que nada falle. Estos tests atacan
 * justo ese punto: comprueban que tras la purga quedan menos filas.
 */
final class RetentionPurgeTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Uuid $organizationId;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        $this->organizationId = Uuid::v7();
    }

    public function testProcessingEventsOlderThanTheThresholdArePurged(): void
    {
        $events = new MessageProcessingEventRepository($this->entityManager);
        $messageId = Uuid::v7();

        $old = new MessageProcessingEvent(
            $messageId,
            MessageProcessingState::RECEIVED,
            MessageProcessingState::IGNORED,
            'Correo descartado',
            new DateTimeImmutable('-90 days'),
        );
        $recent = new MessageProcessingEvent(
            $messageId,
            MessageProcessingState::RECEIVED,
            MessageProcessingState::CANDIDATE,
            'Correo candidato',
            new DateTimeImmutable('-1 day'),
        );

        $this->entityManager->persist($old);
        $this->entityManager->persist($recent);
        $this->entityManager->flush();

        $removed = $events->purgeOlderThan(new DateTimeImmutable('-30 days'));

        self::assertSame(1, $removed, 'Solo debe purgar el evento anterior al umbral.');
        self::assertSame(1, $this->countEvents(), 'El evento reciente debe sobrevivir a la purga.');
    }

    public function testAuditLogsOlderThanTheThresholdArePurged(): void
    {
        $audit = new AuditLogRepository($this->entityManager);

        $this->entityManager->persist(new AuditLog(
            AuditAction::USER_LOGGED_IN,
            $this->organizationId,
            null,
            'user',
            null,
            null,
            [],
            null,
            null,
            new DateTimeImmutable('-200 days'),
        ));
        $this->entityManager->persist(new AuditLog(
            AuditAction::USER_LOGGED_IN,
            $this->organizationId,
            null,
            'user',
            null,
            null,
            [],
            null,
            null,
            new DateTimeImmutable('-2 days'),
        ));
        $this->entityManager->flush();

        $removed = $audit->purgeOlderThan(new DateTimeImmutable('-30 days'));

        self::assertSame(1, $removed);
        self::assertSame(1, $audit->countForOrganization($this->organizationId));
    }

    public function testAiUsagesOlderThanTheThresholdArePurged(): void
    {
        $usages = new AiUsageRepository($this->entityManager);

        $this->entityManager->persist(new AiUsage(
            $this->organizationId,
            'openai',
            AiPurpose::EXTRACTION,
            AiTier::CHEAP,
            'gpt-mini',
            10,
            5,
            3,
            true,
            120,
            null,
            null,
            new DateTimeImmutable('-120 days'),
        ));
        $this->entityManager->persist(new AiUsage(
            $this->organizationId,
            'openai',
            AiPurpose::EXTRACTION,
            AiTier::CHEAP,
            'gpt-mini',
            10,
            5,
            3,
            true,
            120,
            null,
            null,
            new DateTimeImmutable('-1 day'),
        ));
        $this->entityManager->flush();

        $removed = $usages->purgeOlderThan(new DateTimeImmutable('-30 days'));

        self::assertSame(1, $removed);
        self::assertCount(1, $usages->findForOrganization($this->organizationId));
    }

    public function testResolvedAlertsOlderThanTheThresholdArePurgedAndOpenOnesAreKept(): void
    {
        $alerts = new AlertRepository($this->entityManager);

        $resolved = new Alert(
            $this->organizationId,
            AlertType::UPCOMING_CHARGE,
            AlertSeverity::WARNING,
            'Aviso resuelto',
            'Este aviso ya no está abierto.',
            'dedup-resolved',
        );
        $resolved->resolve(new DateTimeImmutable('-60 days'));

        $open = new Alert(
            $this->organizationId,
            AlertType::UPCOMING_RENEWAL,
            AlertSeverity::INFO,
            'Aviso abierto',
            'Este aviso sigue abierto.',
            'dedup-open',
        );

        $this->entityManager->persist($resolved);
        $this->entityManager->persist($open);
        $this->entityManager->flush();

        $removed = $alerts->purgeResolvedBefore(new DateTimeImmutable('-30 days'));

        self::assertSame(1, $removed);
        self::assertCount(1, $alerts->findAllForOrganization($this->organizationId));
        self::assertSame(1, $alerts->countOpen());
    }

    public function testIgnoredDiscoveriesAndTheirEvidenceArePurged(): void
    {
        $discoveries = new DiscoveryRepository($this->entityManager);

        $ignored = new Discovery(
            $this->organizationId,
            DiscoveryType::PRICE_CHANGE,
            new DateTimeImmutable('-120 days'),
        );
        $ignored->ignore(Uuid::v7(), new DateTimeImmutable('-90 days'));

        $pending = new Discovery(
            $this->organizationId,
            DiscoveryType::NEW_SERVICE,
            new DateTimeImmutable('-120 days'),
        );

        $this->entityManager->persist($ignored);
        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        $evidence = new DiscoveryEvidence($ignored->getId(), new DateTimeImmutable('-120 days'), Uuid::v7());
        $this->entityManager->persist($evidence);
        $this->entityManager->flush();

        $removed = $discoveries->purgeIgnoredBefore(new DateTimeImmutable('-30 days'));

        self::assertSame(1, $removed);
        self::assertCount(1, $discoveries->findAllForOrganization($this->organizationId));
        self::assertCount(0, $discoveries->findEvidence($ignored->getId()));
    }

    public function testPurgeRespectsTheBatchLimit(): void
    {
        $audit = new AuditLogRepository($this->entityManager);

        for ($i = 0; $i < 3; ++$i) {
            $this->entityManager->persist(new AuditLog(
                AuditAction::USER_LOGGED_IN,
                $this->organizationId,
                null,
                'user',
                null,
                null,
                [],
                null,
                null,
                new DateTimeImmutable('-90 days'),
            ));
        }
        $this->entityManager->flush();

        $removed = $audit->purgeOlderThan(new DateTimeImmutable('-30 days'), 2);

        self::assertSame(2, $removed, 'La purga se hace en lotes.');
        self::assertSame(1, $audit->countForOrganization($this->organizationId));
    }

    private function countEvents(): int
    {
        return (int) $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(MessageProcessingEvent::class, 'e')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
