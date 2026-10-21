<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Application;

use App\Ai\Application\AiBudget;
use App\Ai\Domain\Entity\AiUsage;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Ai\InMemoryAiUsageRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * El presupuesto existe para que «IA activada» no sea una factura sin límite.
 *
 * Sus dos propiedades son: **degrada** en vez de cortar (el documento se queda
 * en revisión manual, no se pierde), y cuenta el **mes natural**, que es como
 * se factura el consumo de una API.
 */
#[CoversClass(AiBudget::class)]
final class AiBudgetTest extends TestCase
{
    private const NOW = '2026-10-20 09:00:00';

    private Uuid $organizationId;
    private InMemoryAiUsageRepository $usages;
    private TenantContext $tenantContext;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->usages = new InMemoryAiUsageRepository();
        $this->tenantContext = new TenantContext();
        $this->tenantContext->setOrganizationId($this->organizationId);
        $this->clock = new Clock(new MockClock(new DateTimeImmutable(self::NOW)));
    }

    public function testWithoutALimitThereIsNoBudgetToExceed(): void
    {
        $budget = $this->budget(0);

        self::assertFalse($budget->isExhausted());
        self::assertTrue($budget->allowsCall());
    }

    public function testItIsNotExhaustedWithoutSpending(): void
    {
        $budget = $this->budget(500);

        self::assertFalse($budget->isExhausted());
        self::assertSame(500, $budget->remainingMinor());
    }

    public function testItIsExhaustedOnceTheLimitIsReached(): void
    {
        $this->spend(500, self::NOW);
        $budget = $this->budget(500);

        self::assertTrue($budget->isExhausted());
        self::assertFalse($budget->allowsCall());
        self::assertSame(0, $budget->remainingMinor());
    }

    public function testTheRemainingIsNeverNegative(): void
    {
        $this->spend(900, self::NOW);
        $budget = $this->budget(500);

        self::assertSame(0, $budget->remainingMinor());
        self::assertSame(900, $budget->spentMinor());
    }

    public function testTheSpendingOfAnotherOrganizationDoesNotCount(): void
    {
        $other = Uuid::v7();
        $this->usages->save(new AiUsage(
            organizationId: $other,
            providerKey: 'groq',
            purpose: AiPurpose::EXTRACTION,
            tier: AiTier::CHEAP,
            costMinor: 10_000,
            createdAt: new DateTimeImmutable(self::NOW),
        ));

        self::assertSame(0, $this->budget(500)->spentMinor());
    }

    public function testThePreviousMonthDoesNotCount(): void
    {
        $this->spend(10_000, '2026-09-30 23:59:00');
        $this->spend(100, self::NOW);

        self::assertSame(100, $this->budget(500)->spentMinor());
    }

    public function testTheNextMonthDoesNotCountEither(): void
    {
        $this->spend(10_000, '2026-11-01 00:00:00');
        $this->spend(100, self::NOW);

        self::assertSame(100, $this->budget(500)->spentMinor());
    }

    public function testTheMonthIsTheNaturalOne(): void
    {
        $range = $this->budget(500)->currentMonth();

        self::assertSame('2026-10-01 00:00:00', $range->from->format('Y-m-d H:i:s'));
        self::assertNotNull($range->to);
        self::assertSame('2026-11-01 00:00:00', $range->to->format('Y-m-d H:i:s'));
    }

    public function testWithoutAnOrganizationThereIsNoSpending(): void
    {
        $this->tenantContext->setOrganizationId(null);

        self::assertSame(0, $this->budget(500)->spentMinor());
        self::assertFalse($this->budget(500)->isExhausted());
    }

    private function budget(int $monthlyMinor): AiBudget
    {
        return new AiBudget(
            usageRepository: $this->usages,
            tenantContext: $this->tenantContext,
            clock: $this->clock,
            monthlyMinor: $monthlyMinor,
        );
    }

    private function spend(int $costMinor, string $at): void
    {
        $this->usages->save(new AiUsage(
            organizationId: $this->organizationId,
            providerKey: 'groq',
            purpose: AiPurpose::EXTRACTION,
            tier: AiTier::CHEAP,
            costMinor: $costMinor,
            createdAt: new DateTimeImmutable($at),
        ));
    }
}
