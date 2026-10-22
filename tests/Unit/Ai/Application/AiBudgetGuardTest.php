<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Application;

use App\Ai\Application\AiBudgetGuard;
use App\Ai\Domain\Entity\AiUsage;
use App\Ai\Domain\Enum\AiBudgetMode;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Ai\Domain\ValueObject\AiCost;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Ai\InMemoryAiBudgetRepository;
use App\Tests\Support\Ai\InMemoryAiUsageRepository;
use App\Tests\Support\Entitlements\InMemoryEntitlementChecker;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * El guardián del presupuesto de IA (Fase 14, D-55).
 *
 * Lo que se comprueba aquí es la política, no el SQL:
 *
 * - El gasto sale del **libro mayor**, no de un contador guardado aparte.
 * - El modo blando degrada (apaga el nivel avanzado) y el duro para.
 * - Guardar la política crea la fila si no existía, y la actualiza si sí.
 */
#[CoversClass(AiBudgetGuard::class)]
final class AiBudgetGuardTest extends TestCase
{
    private const NOW = '2026-10-20 09:00:00';

    private InMemoryAiUsageRepository $usages;
    private InMemoryAiBudgetRepository $budgets;
    private TenantContext $tenantContext;
    private Uuid $organizationId;

    protected function setUp(): void
    {
        $this->usages = new InMemoryAiUsageRepository();
        $this->budgets = new InMemoryAiBudgetRepository();
        $this->organizationId = Uuid::v7();
        $this->tenantContext = new TenantContext();
        $this->tenantContext->setOrganizationId($this->organizationId);
    }

    public function testWithoutABudgetTheDefaultLimitIsUsed(): void
    {
        $guard = $this->guard(500, AiBudgetMode::SOFT);

        self::assertSame(500, $guard->limitMinor());
        self::assertSame(AiBudgetMode::SOFT, $guard->mode());
        self::assertFalse($guard->isExhausted());
        self::assertTrue($guard->allowsCall());
    }

    public function testTheSpentAmountComesFromTheLedger(): void
    {
        $this->spend(120);
        $this->spend(80);

        $guard = $this->guard(500, AiBudgetMode::SOFT);

        self::assertSame(200, $guard->spentMinor());
        self::assertSame(300, $guard->remainingMinor());
        self::assertFalse($guard->isExhausted());
    }

    public function testTheBudgetIsExhaustedWhenTheSpentAmountReachesTheLimit(): void
    {
        $this->spend(500);

        $guard = $this->guard(500, AiBudgetMode::HARD);

        self::assertTrue($guard->isExhausted());
        self::assertSame(0, $guard->remainingMinor());
        self::assertFalse($guard->allowsCall());
    }

    public function testInSoftModeTheCheapTierKeepsWorkingButTheAdvancedOneDoesNot(): void
    {
        $this->spend(500);

        $guard = $this->guard(500, AiBudgetMode::SOFT);

        self::assertTrue($guard->allowsCall());
        self::assertTrue($guard->allowsTier(ExtractionTier::AI_CHEAP));
        self::assertFalse($guard->allowsTier(ExtractionTier::AI_ADVANCED));
    }

    public function testInHardModeNoTierIsAllowed(): void
    {
        $this->spend(500);

        $guard = $this->guard(500, AiBudgetMode::HARD);

        self::assertFalse($guard->allowsCall());
        self::assertFalse($guard->allowsTier(ExtractionTier::AI_CHEAP));
        self::assertFalse($guard->allowsTier(ExtractionTier::AI_ADVANCED));
    }

    public function testTheNonAiTiersAreAlwaysAllowed(): void
    {
        $this->spend(500);

        $guard = $this->guard(500, AiBudgetMode::HARD);

        self::assertTrue($guard->allowsTier(ExtractionTier::DETERMINISTIC));
        self::assertTrue($guard->allowsTier(ExtractionTier::KNOWN_PARSER));
    }

    public function testWithoutALimitTheBudgetIsNeverExhausted(): void
    {
        $this->spend(100000);

        $guard = $this->guard(0, AiBudgetMode::HARD);

        self::assertFalse($guard->isExhausted());
        self::assertTrue($guard->allowsCall());
        self::assertSame(0, $guard->remainingMinor());
    }

    public function testConfiguringTheBudgetCreatesThePolicy(): void
    {
        $guard = $this->guard(500, AiBudgetMode::SOFT);

        $budget = $guard->configure(2500, AiBudgetMode::HARD);

        self::assertSame(2500, $budget->getLimitMinor());
        self::assertSame(AiBudgetMode::HARD, $budget->getMode());
        self::assertSame('2026-10', $budget->getPeriod());
        self::assertCount(1, $this->budgets->all());

        // El guardián ya lee la política nueva.
        self::assertSame(2500, $guard->limitMinor());
        self::assertSame(AiBudgetMode::HARD, $guard->mode());
    }

    public function testConfiguringTheBudgetUpdatesTheExistingPolicy(): void
    {
        $guard = $this->guard(500, AiBudgetMode::SOFT);

        $guard->configure(2500, AiBudgetMode::HARD);
        $guard->configure(1000, AiBudgetMode::SOFT);

        self::assertCount(1, $this->budgets->all());
        self::assertSame(1000, $guard->limitMinor());
        self::assertSame(AiBudgetMode::SOFT, $guard->mode());
    }

    public function testTheStatusDescribesTheBudget(): void
    {
        $this->spend(400);

        $guard = $this->guard(500, AiBudgetMode::SOFT);
        $status = $guard->status();

        self::assertSame(500, $status->limitMinor());
        self::assertSame(400, $status->spentMinor());
        self::assertSame(100, $status->remainingMinor());
        self::assertSame(80, $status->usedPercent);
        self::assertTrue($status->warning);
        self::assertFalse($status->exhausted);
        self::assertFalse($status->isUnlimited());
    }

    public function testTheStatusOfAnUnlimitedBudget(): void
    {
        $guard = $this->guard(0, AiBudgetMode::SOFT);
        $status = $guard->status();

        self::assertTrue($status->isUnlimited());
        self::assertSame(0, $status->usedPercent);
        self::assertFalse($status->warning);
        self::assertFalse($status->exhausted);
    }

    public function testThePeriodIsTheNaturalMonth(): void
    {
        $guard = $this->guard(500, AiBudgetMode::SOFT);

        self::assertSame('2026-10', $guard->currentPeriodKey());
        self::assertSame('2026-10-01 00:00:00', $guard->currentPeriod()->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-01 00:00:00', $guard->currentPeriod()->to?->format('Y-m-d H:i:s'));
    }

    private function guard(int $limitMinor, AiBudgetMode $mode): AiBudgetGuard
    {
        return new AiBudgetGuard(
            budgets: $this->budgets,
            usage: $this->usages,
            tenantContext: $this->tenantContext,
            clock: new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
            entitlements: InMemoryEntitlementChecker::allowingEverything(),
            defaultLimitMinor: $limitMinor,
            defaultMode: $mode,
        );
    }

    private function spend(int $costMinor): void
    {
        $this->usages->save(new AiUsage(
            organizationId: $this->organizationId,
            providerKey: 'groq',
            purpose: AiPurpose::EXTRACTION,
            tier: AiTier::CHEAP,
            costMicro: AiCost::toMicro($costMinor),
            createdAt: new DateTimeImmutable(self::NOW),
        ));
    }
}
