<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Application;

use App\Identity\Domain\Entity\User;
use App\Notifications\Application\Dto\NotificationPreferenceFormData;
use App\Notifications\Application\UpdateNotificationPreferences;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Enum\NotificationChannel;
use App\Notifications\Domain\Service\AlertRules;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Notifications\InMemoryNotificationPreferenceRepository;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Las preferencias se guardan como **desviaciones** del valor por defecto. Es la
 * decisión que permite cambiar los valores por defecto del producto sin migrar
 * los datos de nadie, así que se prueba explícitamente.
 */
final class UpdateNotificationPreferencesTest extends TestCase
{
    private Uuid $organizationId;
    private InMemoryNotificationPreferenceRepository $preferences;
    private AuditLoggerInterface&MockObject $auditLogger;
    private User $user;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->preferences = new InMemoryNotificationPreferenceRepository();
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->user = new User('ana@ejemplo.com', 'hash', 'Ana');
        $this->now = new DateTimeImmutable('2026-10-05 09:00:00');
    }

    public function testSavingTheDefaultsStoresNothing(): void
    {
        $changed = $this->update($this->defaults());

        self::assertSame(0, $changed);
        self::assertSame([], $this->preferences->all());
    }

    public function testOptingIntoEmailStoresOnlyThatDeviation(): void
    {
        $data = $this->defaults();
        $data->enabled[AlertType::UPCOMING_RENEWAL->value][NotificationChannel::EMAIL->value] = true;

        $changed = $this->update($data);

        self::assertSame(1, $changed);
        self::assertCount(1, $this->preferences->all());

        $stored = $this->preferences->findOne($this->user->getId(), AlertType::UPCOMING_RENEWAL, NotificationChannel::EMAIL);

        self::assertNotNull($stored);
        self::assertTrue($stored->isEnabled());
    }

    public function testDisablingInAppStoresTheDeviation(): void
    {
        $data = $this->defaults();
        $data->enabled[AlertType::PRICE_INCREASE->value][NotificationChannel::IN_APP->value] = false;

        $this->update($data);

        $stored = $this->preferences->findOne($this->user->getId(), AlertType::PRICE_INCREASE, NotificationChannel::IN_APP);

        self::assertNotNull($stored);
        self::assertFalse($stored->isEnabled());
    }

    public function testGoingBackToTheDefaultDeletesTheRow(): void
    {
        $data = $this->defaults();
        $data->enabled[AlertType::UPCOMING_RENEWAL->value][NotificationChannel::EMAIL->value] = true;

        $this->update($data);
        self::assertCount(1, $this->preferences->all());

        $back = $this->defaults();
        $changed = $this->update($back);

        self::assertSame(1, $changed);
        self::assertSame([], $this->preferences->all());
    }

    public function testSavingTheSameValueTwiceChangesNothingTheSecondTime(): void
    {
        $data = $this->defaults();
        $data->enabled[AlertType::UPCOMING_RENEWAL->value][NotificationChannel::EMAIL->value] = true;

        self::assertSame(1, $this->update($data));
        self::assertSame(0, $this->update($data));
    }

    public function testItAuditsOnlyWhenSomethingChanged(): void
    {
        $this->auditLogger->expects(self::once())->method('log');

        $this->update($this->defaults());

        $data = $this->defaults();
        $data->enabled[AlertType::UPCOMING_RENEWAL->value][NotificationChannel::EMAIL->value] = true;

        $this->update($data);
    }

    public function testAMissingCellFallsBackToTheDefault(): void
    {
        $data = new NotificationPreferenceFormData();

        self::assertSame(0, $this->update($data));
        self::assertSame([], $this->preferences->all());
    }

    private function update(NotificationPreferenceFormData $data): int
    {
        $useCase = new UpdateNotificationPreferences(
            preferences: $this->preferences,
            tenantContext: $this->tenantContext(),
            auditLogger: $this->auditLogger,
            clock: new Clock(new MockClock($this->now)),
        );

        return $useCase($this->user, $data, $this->user->getId());
    }

    private function tenantContext(): TenantContext
    {
        $context = new TenantContext();
        $context->setOrganizationId($this->organizationId);

        return $context;
    }

    private function defaults(): NotificationPreferenceFormData
    {
        $data = new NotificationPreferenceFormData();

        foreach (AlertType::cases() as $type) {
            foreach (NotificationChannel::cases() as $channel) {
                $data->enabled[$type->value][$channel->value] = AlertRules::defaultEnabled($type, $channel);
            }
        }

        return $data;
    }
}
