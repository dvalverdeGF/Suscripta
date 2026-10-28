<?php

declare(strict_types=1);

namespace App\Tests\Unit\Subscriptions;

use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Subscriptions\Domain\Entity\Subscription;
use App\Subscriptions\Domain\Enum\SubscriptionStatus;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Reglas de vida de una suscripción: periodo, renovación, pausa y cancelación.
 *
 * Todo el test pasa fechas explícitas porque el dominio las acepta como
 * parámetro: sin eso, comprobar «renovar cuando el periodo ya venció» dependería
 * del día en que se ejecuta la suite.
 */
final class SubscriptionTest extends TestCase
{
    public function testThePeriodEndsAccordingToTheBillingPeriod(): void
    {
        $subscription = $this->subscription(startedAt: '2026-01-31');

        self::assertSame('2026-01-31', $subscription->getCurrentPeriodStart()->format('Y-m-d'));
        self::assertSame('2026-02-28', $subscription->getCurrentPeriodEnd()->format('Y-m-d'));
    }

    public function testTheIntervalMultipliesThePeriod(): void
    {
        $subscription = $this->subscription(startedAt: '2026-01-15', period: BillingPeriod::QUARTERLY, interval: 2);

        self::assertSame('2026-07-15', $subscription->getCurrentPeriodEnd()->format('Y-m-d'));
    }

    public function testASubscriptionCannotBeOneTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no hay nada que renovar');

        $this->subscription(period: BillingPeriod::ONE_TIME);
    }

    public function testTheNameIsTrimmedAndCannotBeEmpty(): void
    {
        self::assertSame('Plan Pro', $this->subscription(name: '  Plan Pro  ')->getName());

        $this->expectException(InvalidArgumentException::class);
        $this->subscription(name: '   ');
    }

    public function testChangingThePeriodRecalculatesFromThePeriodStart(): void
    {
        $subscription = $this->subscription(startedAt: '2026-03-10');

        $subscription->changeBillingPeriod(BillingPeriod::ANNUAL);

        self::assertSame(BillingPeriod::ANNUAL, $subscription->getBillingPeriod());
        self::assertSame('2026-03-10', $subscription->getCurrentPeriodStart()->format('Y-m-d'));
        self::assertSame('2027-03-10', $subscription->getCurrentPeriodEnd()->format('Y-m-d'));
    }

    public function testRenewingMovesThePeriodForward(): void
    {
        $subscription = $this->subscription(startedAt: '2026-01-01');

        $subscription->renew(new DateTimeImmutable('2026-01-20'));

        self::assertSame('2026-02-01', $subscription->getCurrentPeriodStart()->format('Y-m-d'));
        self::assertSame('2026-03-01', $subscription->getCurrentPeriodEnd()->format('Y-m-d'));
    }

    public function testRenewingAnExpiredPeriodStartsToday(): void
    {
        $subscription = $this->subscription(startedAt: '2026-01-01');

        // El periodo venció el 1 de febrero y el worker no corrió hasta marzo.
        $subscription->renew(new DateTimeImmutable('2026-03-15'));

        self::assertSame('2026-03-15', $subscription->getCurrentPeriodStart()->format('Y-m-d'));
        self::assertSame('2026-04-15', $subscription->getCurrentPeriodEnd()->format('Y-m-d'));
    }

    public function testOnlyAnActiveSubscriptionCanBeRenewed(): void
    {
        $subscription = $this->subscription();
        $subscription->pause();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Solo se renueva una suscripción activa');

        $subscription->renew(new DateTimeImmutable('2026-01-20'));
    }

    public function testActivatingATrialStartsThePeriodToday(): void
    {
        $subscription = $this->subscription(startedAt: '2026-01-01');
        $subscription->startTrial(new DateTimeImmutable('2026-01-15'), new DateTimeImmutable('2026-01-01'));

        self::assertSame(SubscriptionStatus::TRIALING, $subscription->getStatus());

        $subscription->activate(new DateTimeImmutable('2026-01-20'));

        self::assertSame(SubscriptionStatus::ACTIVE, $subscription->getStatus());
        self::assertSame('2026-01-20', $subscription->getCurrentPeriodStart()->format('Y-m-d'));
        self::assertSame('2026-02-20', $subscription->getCurrentPeriodEnd()->format('Y-m-d'));
        self::assertNull($subscription->getTrialEndsAt());
    }

    public function testATrialCannotEndInThePast(): void
    {
        $subscription = $this->subscription();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('posterior a hoy');

        $subscription->startTrial(new DateTimeImmutable('2026-01-15'), new DateTimeImmutable('2026-01-15'));
    }

    public function testPausingDoesNotMoveThePeriodAndResumingRestartsIt(): void
    {
        $subscription = $this->subscription(startedAt: '2026-01-01');

        $subscription->pause();
        self::assertSame(SubscriptionStatus::PAUSED, $subscription->getStatus());
        self::assertFalse($subscription->isLive());
        self::assertSame('2026-02-01', $subscription->getCurrentPeriodEnd()->format('Y-m-d'));

        $subscription->resume(new DateTimeImmutable('2026-02-10'));
        self::assertSame(SubscriptionStatus::ACTIVE, $subscription->getStatus());
        self::assertSame('2026-02-10', $subscription->getCurrentPeriodStart()->format('Y-m-d'));
        self::assertSame('2026-03-10', $subscription->getCurrentPeriodEnd()->format('Y-m-d'));
    }

    public function testCancellingKeepsThePaidPeriodAndIsIdempotent(): void
    {
        $subscription = $this->subscription(startedAt: '2026-01-01');

        $subscription->cancel(new DateTimeImmutable('2026-01-10'));

        self::assertSame(SubscriptionStatus::CANCELED, $subscription->getStatus());
        self::assertSame('2026-02-01', $subscription->getEndsAt()?->format('Y-m-d'));
        self::assertSame('2026-01-10', $subscription->getCanceledAt()?->format('Y-m-d'));
        self::assertFalse($subscription->isLive());

        // Cancelar dos veces no reescribe la fecha de baja.
        $subscription->cancel(new DateTimeImmutable('2026-01-20'));

        self::assertSame('2026-01-10', $subscription->getCanceledAt()?->format('Y-m-d'));
    }

    public function testACancelledSubscriptionCannotComeBack(): void
    {
        $subscription = $this->subscription();
        $subscription->cancel();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('hay que crear otra');

        $subscription->activate();
    }

    public function testMarkingPastDueKeepsTheSubscriptionAlive(): void
    {
        $subscription = $this->subscription();

        $subscription->markPastDue();

        self::assertSame(SubscriptionStatus::PAST_DUE, $subscription->getStatus());
        self::assertTrue($subscription->isLive());
        self::assertFalse($subscription->getStatus()->isPaid());
    }

    public function testDaysLeftIsNegativeWhenThePeriodExpired(): void
    {
        $subscription = $this->subscription(startedAt: '2026-01-01');

        self::assertSame(10, $subscription->daysLeft(new DateTimeImmutable('2026-01-22')));
        self::assertSame(-3, $subscription->daysLeft(new DateTimeImmutable('2026-02-04')));
    }

    private function subscription(
        string $name = 'Plan Pro',
        string $startedAt = '2026-01-01',
        BillingPeriod $period = BillingPeriod::MONTHLY,
        int $interval = 1,
    ): Subscription {
        return new Subscription(
            organizationId: Uuid::v7(),
            name: $name,
            currency: Currency::EUR,
            billingPeriod: $period,
            billingIntervalCount: $interval,
            startedAt: new DateTimeImmutable($startedAt),
        );
    }
}
