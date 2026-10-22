<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Application;

use App\Catalog\Domain\Entity\Provider;
use App\Catalog\Domain\Repository\ProviderRepositoryInterface;
use App\Discovery\Application\BatchReviewDiscoveries;
use App\Discovery\Application\ClearSourceExcerpt;
use App\Discovery\Application\ConfirmDiscovery;
use App\Discovery\Application\DismissDiscovery;
use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryStatus;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Processing\Application\Knowledge\LearnFromConfirmation;
use App\Processing\Application\Knowledge\LearnFromDismissal;
use App\Processing\Application\Knowledge\LearnFromMessage;
use App\Processing\Application\Knowledge\RecordSenderRejection;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use App\Services\Application\CreateService;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Revisión por lotes de la bandeja de propuestas.
 *
 * La propiedad que define este servicio es que **una propuesta que falla no
 * aborta el lote**. Perder veinte propuestas porque la primera ya estaba
 * confirmada sería peor que el error: el usuario repetiría el trabajo.
 */
#[CoversClass(BatchReviewDiscoveries::class)]
final class BatchReviewDiscoveriesTest extends TestCase
{
    private const NOW = '2026-04-02 10:00:00';

    private Uuid $organizationId;
    private DiscoveryRepositoryInterface&MockObject $discoveries;
    private ServiceRepositoryInterface&MockObject $services;
    private ProviderRepositoryInterface&MockObject $providers;
    private AuditLoggerInterface&MockObject $auditLogger;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->discoveries = $this->createMock(DiscoveryRepositoryInterface::class);
        $this->services = $this->createMock(ServiceRepositoryInterface::class);
        $this->providers = $this->createMock(ProviderRepositoryInterface::class);
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);

        $this->tenantContext = new TenantContext();
        $this->tenantContext->setOrganizationId($this->organizationId);
    }

    public function testItConfirmsEveryProposalInTheBatch(): void
    {
        $first = $this->proposal('Netflix');
        $second = $this->proposal('Spotify');

        $result = $this->batchReview()->confirm([$first, $second]);

        self::assertSame(2, $result->confirmed);
        self::assertSame(0, $result->failed);
        self::assertSame(DiscoveryStatus::CONFIRMED, $first->getStatus());
        self::assertSame(DiscoveryStatus::CONFIRMED, $second->getStatus());
    }

    public function testAFailingProposalDoesNotAbortTheBatch(): void
    {
        $alreadyConfirmed = $this->proposal('Netflix');
        $alreadyConfirmed->confirm(Uuid::v7(), new DateTimeImmutable(self::NOW));

        $pending = $this->proposal('Spotify');

        $result = $this->batchReview()->confirm([$alreadyConfirmed, $pending]);

        self::assertSame(1, $result->confirmed);
        self::assertSame(1, $result->failed);
        // La segunda propuesta sí se procesó.
        self::assertSame(DiscoveryStatus::CONFIRMED, $pending->getStatus());
    }

    public function testItCreatesTheServiceWithTheEmailDiscoverySource(): void
    {
        $created = null;
        $this->services->method('save')->willReturnCallback(
            static function ($service) use (&$created): void {
                $created = $service;
            },
        );

        $this->batchReview()->confirm([$this->proposal('Netflix')]);

        self::assertNotNull($created);
        self::assertSame('Netflix', $created->getName());
        self::assertSame('email_discovery', $created->getSource()->value);
    }

    public function testItResolvesTheProviderFromTheCatalogByName(): void
    {
        $provider = new Provider('Netflix', 'netflix');
        $this->providers->method('findVisibleForOrganization')->willReturn([$provider]);

        $created = null;
        $this->services->method('save')->willReturnCallback(
            static function ($service) use (&$created): void {
                $created = $service;
            },
        );

        $this->batchReview()->confirm([$this->proposal('Netflix')]);

        self::assertNotNull($created);
        self::assertTrue($provider->getId()->equals($created->getProviderId()));
    }

    public function testItResolvesTheProviderByAlias(): void
    {
        $provider = new Provider('Netflix', 'netflix');
        $provider->setAliases(['Netflix Premium', 'Nflx']);
        $this->providers->method('findVisibleForOrganization')->willReturn([$provider]);

        $created = null;
        $this->services->method('save')->willReturnCallback(
            static function ($service) use (&$created): void {
                $created = $service;
            },
        );

        $this->batchReview()->confirm([$this->proposal('Nflx')]);

        self::assertNotNull($created);
        self::assertTrue($provider->getId()->equals($created->getProviderId()));
    }

    public function testAnUnknownProviderLeavesTheServiceWithoutProvider(): void
    {
        $this->providers->method('findVisibleForOrganization')->willReturn([]);

        $created = null;
        $this->services->method('save')->willReturnCallback(
            static function ($service) use (&$created): void {
                $created = $service;
            },
        );

        $result = $this->batchReview()->confirm([$this->proposal('Marca Desconocida')]);

        self::assertSame(1, $result->confirmed);
        self::assertNotNull($created);
        self::assertNull($created->getProviderId());
    }

    public function testItDismissesEveryProposalInTheBatch(): void
    {
        $first = $this->proposal('Netflix');
        $second = $this->proposal('Spotify');

        $result = $this->batchReview()->dismiss([$first, $second], 'No lo uso');

        self::assertSame(2, $result->dismissed);
        self::assertSame(0, $result->failed);
        self::assertSame(DiscoveryStatus::IGNORED, $first->getStatus());
        self::assertSame(DiscoveryStatus::IGNORED, $second->getStatus());
    }

    public function testAFailingDismissalDoesNotAbortTheBatch(): void
    {
        $alreadyIgnored = $this->proposal('Netflix');
        $alreadyIgnored->ignore(Uuid::v7(), new DateTimeImmutable(self::NOW), 'ya');

        $pending = $this->proposal('Spotify');

        $result = $this->batchReview()->dismiss([$alreadyIgnored, $pending]);

        self::assertSame(1, $result->dismissed);
        self::assertSame(1, $result->failed);
        self::assertSame(DiscoveryStatus::IGNORED, $pending->getStatus());
    }

    public function testTheResultSummarisesWhatHappened(): void
    {
        $result = $this->batchReview()->confirm([$this->proposal('Netflix')]);

        self::assertSame(1, $result->processed());
        self::assertStringContainsString('1 confirmadas', $result->summary());
    }

    private function batchReview(): BatchReviewDiscoveries
    {
        $clock = new Clock(new MockClock(new DateTimeImmutable(self::NOW)));

        $createService = new CreateService(
            $this->services,
            $this->tenantContext,
            $this->auditLogger,
        );

        $knowledgeEntries = $this->createMock(MailboxKnowledgeRepositoryInterface::class);
        $knowledgeEntries->method('find')->willReturn(null);
        $knowledgeEntries->method('findByValue')->willReturn(null);

        $messages = $this->createMock(EmailMessageRepositoryInterface::class);
        $messages->method('find')->willReturn(null);

        $learnFromMessage = new LearnFromMessage($knowledgeEntries, $clock);

        $confirmDiscovery = new ConfirmDiscovery(
            $this->discoveries,
            $this->services,
            $createService,
            $this->tenantContext,
            $this->auditLogger,
            $clock,
            new LearnFromConfirmation($messages, $learnFromMessage),
            new ClearSourceExcerpt($messages),
        );

        $dismissDiscovery = new DismissDiscovery(
            $this->discoveries,
            $this->tenantContext,
            $this->auditLogger,
            $clock,
            new LearnFromDismissal($messages, new RecordSenderRejection($knowledgeEntries, $clock)),
            new ClearSourceExcerpt($messages),
        );

        return new BatchReviewDiscoveries(
            $confirmDiscovery,
            $dismissDiscovery,
            $this->providers,
            $this->tenantContext,
        );
    }

    private function proposal(string $providerName): Discovery
    {
        return new Discovery(
            $this->organizationId,
            DiscoveryType::NEW_SERVICE,
            new DateTimeImmutable(self::NOW),
            [
                'providerName' => $providerName,
                'amountMinor' => 1290,
                'currency' => 'EUR',
                'billingPeriod' => 'monthly',
            ],
        );
    }
}
