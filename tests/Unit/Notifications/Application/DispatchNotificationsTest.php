<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Application;

use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Enum\OrganizationRole;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Notifications\Application\Channel\NotificationChannelRegistry;
use App\Notifications\Application\DispatchNotifications;
use App\Notifications\Application\Dto\NotificationDispatchResult;
use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Entity\NotificationPreference;
use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Enum\NotificationChannel;
use App\Notifications\Domain\Enum\NotificationStatus;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Notifications\InMemoryAlertRepository;
use App\Tests\Support\Notifications\InMemoryNotificationPreferenceRepository;
use App\Tests\Support\Notifications\InMemoryNotificationRepository;
use App\Tests\Support\Notifications\RecordingChannel;

use function array_filter;
use function array_values;

use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * La política de entrega es lo que separa un producto útil de uno que manda
 * correos que nadie lee. Aquí se prueba que lo urgente siempre sale, que lo
 * silenciado no sale y que un envío fallido se reintenta.
 */
final class DispatchNotificationsTest extends TestCase
{
    private Uuid $organizationId;
    private InMemoryAlertRepository $alerts;
    private InMemoryNotificationRepository $notifications;
    private InMemoryNotificationPreferenceRepository $preferences;
    private OrganizationRepositoryInterface&MockObject $organizations;
    private AuditLoggerInterface&MockObject $auditLogger;
    private RecordingChannel $inApp;
    private RecordingChannel $email;
    private User $user;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->alerts = new InMemoryAlertRepository();
        $this->notifications = new InMemoryNotificationRepository();
        $this->preferences = new InMemoryNotificationPreferenceRepository();
        $this->organizations = $this->createMock(OrganizationRepositoryInterface::class);
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->inApp = new RecordingChannel(NotificationChannel::IN_APP);
        $this->email = new RecordingChannel(NotificationChannel::EMAIL);
        $this->now = new DateTimeImmutable('2026-10-05 09:00:00');

        $this->user = new User('ana@ejemplo.com', 'hash', 'Ana');
        $organization = new Organization('Acme', 'acme');

        $this->organizations->method('findMembers')->willReturn([
            ['user' => $this->user, 'role' => OrganizationRole::OWNER],
        ]);
    }

    public function testItDeliversAnOpenAlertInApp(): void
    {
        $this->withAlert(AlertSeverity::WARNING);

        $result = $this->dispatch();

        self::assertSame(1, $result->sent);
        self::assertSame(1, $this->inApp->deliveryCount());
        self::assertSame(0, $this->email->deliveryCount());
    }

    public function testACriticalAlertIsAlwaysEmailed(): void
    {
        $this->withAlert(AlertSeverity::CRITICAL);

        $result = $this->dispatch();

        self::assertSame(2, $result->sent);
        self::assertSame(1, $this->email->deliveryCount());
    }

    public function testACriticalAlertIsEmailedEvenWhenTheUserDisabledEmail(): void
    {
        $this->withAlert(AlertSeverity::CRITICAL);
        $this->disable(AlertType::UPCOMING_CHARGE, NotificationChannel::EMAIL);

        $this->dispatch();

        self::assertSame(1, $this->email->deliveryCount());
    }

    public function testANonCriticalAlertIsNotEmailedByDefault(): void
    {
        $this->withAlert(AlertSeverity::WARNING);

        $this->dispatch();

        self::assertSame(0, $this->email->deliveryCount());
    }

    public function testTheUserCanOptIntoEmailForANonCriticalAlert(): void
    {
        $this->withAlert(AlertSeverity::WARNING);
        $this->enable(AlertType::UPCOMING_CHARGE, NotificationChannel::EMAIL);

        $this->dispatch();

        self::assertSame(1, $this->email->deliveryCount());
    }

    public function testADisabledPreferenceCreatesNoNotificationRow(): void
    {
        $this->withAlert(AlertSeverity::WARNING);
        $this->disable(AlertType::UPCOMING_CHARGE, NotificationChannel::IN_APP);

        $result = $this->dispatch();

        self::assertSame(0, $result->sent);
        self::assertSame(0, $this->notifications->count());
    }

    public function testRunningTwiceDoesNotResend(): void
    {
        $this->withAlert(AlertSeverity::WARNING);

        $this->dispatch();
        $result = $this->dispatch();

        self::assertSame(0, $result->sent);
        self::assertSame(1, $result->alreadyDelivered);
        self::assertSame(1, $this->inApp->deliveryCount());
    }

    public function testAFailedDeliveryIsRetried(): void
    {
        $this->withAlert(AlertSeverity::CRITICAL);
        $this->email->willFail('El servidor de correo no responde.');

        $first = $this->dispatch();

        self::assertSame(1, $first->failed);
        self::assertSame(1, $this->email->deliveryCount());

        $this->email = new RecordingChannel(NotificationChannel::EMAIL);

        $second = $this->dispatch();

        self::assertSame(1, $second->sent);
        self::assertSame(1, $this->email->deliveryCount());
    }

    public function testASkippedDeliveryIsNotRetried(): void
    {
        $this->withAlert(AlertSeverity::CRITICAL);
        $this->email->willSkip('El usuario no tiene correo configurado.');

        $first = $this->dispatch();

        self::assertSame(1, $first->skipped);

        $this->email = new RecordingChannel(NotificationChannel::EMAIL);

        $second = $this->dispatch();

        self::assertSame(0, $second->sent);
        self::assertSame(0, $this->email->deliveryCount());
    }

    public function testItRecordsTheFailureReason(): void
    {
        $this->withAlert(AlertSeverity::CRITICAL);
        $this->email->willFail('El servidor de correo no responde.');

        $this->dispatch();

        $failed = array_values(array_filter(
            $this->notifications->all(),
            static fn ($notification): bool => NotificationStatus::FAILED === $notification->getStatus(),
        ));

        self::assertCount(1, $failed);
        self::assertSame('El servidor de correo no responde.', $failed[0]->getError());
    }

    public function testItAuditsEmailDeliveriesButNotInAppOnes(): void
    {
        $this->withAlert(AlertSeverity::WARNING);

        $this->auditLogger->expects(self::never())->method('log');

        $this->dispatch();
    }

    public function testItAuditsAFailedDelivery(): void
    {
        $this->withAlert(AlertSeverity::CRITICAL);
        $this->email->willFail('Sin conexión.');

        $this->auditLogger->expects(self::once())->method('log');

        $this->dispatch();
    }

    public function testItDoesNothingWithoutMembers(): void
    {
        $this->withAlert(AlertSeverity::WARNING);

        $this->organizations = $this->createMock(OrganizationRepositoryInterface::class);
        $this->organizations->method('findMembers')->willReturn([]);

        $result = $this->dispatch();

        self::assertSame(0, $result->total());
        self::assertSame(0, $this->inApp->deliveryCount());
    }

    public function testItOnlyDeliversOpenAlerts(): void
    {
        $alert = $this->withAlert(AlertSeverity::WARNING);
        $alert->dismiss($this->user->getId(), $this->now);

        $result = $this->dispatch();

        self::assertSame(0, $result->total());
    }

    private function dispatch(): NotificationDispatchResult
    {
        $dispatcher = new DispatchNotifications(
            alerts: $this->alerts,
            notifications: $this->notifications,
            preferences: $this->preferences,
            organizations: $this->organizations,
            channels: new NotificationChannelRegistry([$this->inApp, $this->email]),
            tenantContext: $this->tenantContext(),
            auditLogger: $this->auditLogger,
            clock: new Clock(new MockClock($this->now)),
        );

        return $dispatcher();
    }

    private function tenantContext(): TenantContext
    {
        $context = new TenantContext();
        $context->setOrganizationId($this->organizationId);

        return $context;
    }

    private function withAlert(AlertSeverity $severity): Alert
    {
        $alert = new Alert(
            organizationId: $this->organizationId,
            type: AlertType::UPCOMING_CHARGE,
            severity: $severity,
            title: 'Cobro próximo: OVH',
            message: 'OVH te cobrará 29,90 € el 08/10/2026.',
            dedupKey: 'upcoming_charge|abc|2026-10-08',
            dueAt: new DateTimeImmutable('2026-10-08'),
            createdAt: $this->now,
        );

        $this->alerts->save($alert);

        return $alert;
    }

    private function enable(AlertType $type, NotificationChannel $channel): void
    {
        $this->preferences->save(new NotificationPreference(
            organizationId: $this->organizationId,
            userId: $this->user->getId(),
            alertType: $type,
            channel: $channel,
            enabled: true,
            updatedAt: $this->now,
        ));
    }

    private function disable(AlertType $type, NotificationChannel $channel): void
    {
        $this->preferences->save(new NotificationPreference(
            organizationId: $this->organizationId,
            userId: $this->user->getId(),
            alertType: $type,
            channel: $channel,
            enabled: false,
            updatedAt: $this->now,
        ));
    }
}
