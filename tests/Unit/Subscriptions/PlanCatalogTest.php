<?php

declare(strict_types=1);

namespace App\Tests\Unit\Subscriptions;

use App\Identity\Domain\Enum\OrganizationPlan;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use App\Subscriptions\Domain\Entity\Plan;
use App\Subscriptions\Domain\Entity\PlanFeature;
use App\Subscriptions\Domain\Entity\PlanPrice;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Reglas del catálogo comercial que no necesitan base de datos.
 *
 * Son las que el panel puede romper sin querer: publicar un plan sin nombre,
 * poner a la venta una periodicidad que no se vende, cerrar un precio dos veces
 * o dejar dos precios vigentes del mismo periodo.
 */
final class PlanCatalogTest extends TestCase
{
    public function testPlanTrimsItsNameAndRejectsAnEmptyOne(): void
    {
        $plan = new Plan(OrganizationPlan::PRO, '  Profesional  ');

        self::assertSame('Profesional', $plan->getName());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('El plan necesita un nombre.');

        $plan->rename('   ');
    }

    public function testPlanRejectsATaglineThatIsTooLong(): void
    {
        $plan = new Plan(OrganizationPlan::PRO, 'Profesional');

        $this->expectException(InvalidArgumentException::class);

        $plan->describe(str_repeat('a', Plan::MAX_TAGLINE_LENGTH + 1), null);
    }

    public function testRetiringAPlanKeepsItReadable(): void
    {
        $plan = new Plan(OrganizationPlan::PRO, 'Profesional');
        $plan->setActive(false);

        self::assertFalse($plan->isActive());
        self::assertSame('Profesional', $plan->getName());
    }

    public function testPositionCannotBeNegative(): void
    {
        $plan = new Plan(OrganizationPlan::FREE, 'Gratis');

        $this->expectException(InvalidArgumentException::class);

        $plan->moveTo(-1);
    }

    public function testPriceRefusesAPeriodThatIsNotForSale(): void
    {
        $plan = new Plan(OrganizationPlan::PRO, 'Profesional');

        $this->expectException(InvalidArgumentException::class);

        new PlanPrice($plan, Money::of(1900, Currency::EUR), BillingPeriod::WEEKLY);
    }

    public function testPriceRefusesANegativeAmount(): void
    {
        $plan = new Plan(OrganizationPlan::PRO, 'Profesional');

        $this->expectException(InvalidArgumentException::class);

        new PlanPrice($plan, Money::of(-1, Currency::EUR), BillingPeriod::MONTHLY);
    }

    public function testClosingAPriceTwiceKeepsTheFirstDate(): void
    {
        $plan = new Plan(OrganizationPlan::PRO, 'Profesional');
        $price = new PlanPrice($plan, Money::of(1900, Currency::EUR), BillingPeriod::MONTHLY, new DateTimeImmutable('2026-01-01'));

        self::assertTrue($price->isCurrent());

        $price->close(new DateTimeImmutable('2026-03-01'));
        $price->close(new DateTimeImmutable('2026-05-01'));

        self::assertFalse($price->isCurrent());
        self::assertSame('2026-03-01', $price->getValidTo()?->format('Y-m-d'));
    }

    public function testPriceCannotBeClosedBeforeItStarts(): void
    {
        $plan = new Plan(OrganizationPlan::PRO, 'Profesional');
        $price = new PlanPrice($plan, Money::of(1900, Currency::EUR), BillingPeriod::MONTHLY, new DateTimeImmutable('2026-03-01'));

        $this->expectException(InvalidArgumentException::class);

        $price->close(new DateTimeImmutable('2026-02-01'));
    }

    public function testPriceMatchesItsOwnPeriodAndCurrencyOnly(): void
    {
        $plan = new Plan(OrganizationPlan::PRO, 'Profesional');
        $price = new PlanPrice($plan, Money::of(1900, Currency::EUR), BillingPeriod::MONTHLY);

        self::assertTrue($price->matches(BillingPeriod::MONTHLY, Currency::EUR));
        self::assertFalse($price->matches(BillingPeriod::ANNUAL, Currency::EUR));
        self::assertFalse($price->matches(BillingPeriod::MONTHLY, Currency::USD));
    }

    public function testFeatureTrimsItsLabelAndRejectsAnEmptyOne(): void
    {
        $plan = new Plan(OrganizationPlan::PRO, 'Profesional');
        $feature = new PlanFeature($plan, '  Copias de seguridad  ', true);

        self::assertSame('Copias de seguridad', $feature->getLabel());
        self::assertTrue($feature->isIncluded());

        $this->expectException(InvalidArgumentException::class);

        $feature->rename('  ');
    }

    public function testFeatureCanBeMarkedAsNotIncluded(): void
    {
        $plan = new Plan(OrganizationPlan::PRO, 'Profesional');
        $feature = new PlanFeature($plan, 'Inteligencia artificial', true);

        $feature->setIncluded(false);

        self::assertFalse($feature->isIncluded());
    }
}
