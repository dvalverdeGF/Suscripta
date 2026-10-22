<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Application;

use App\Ai\Application\AiBudgetGuard;
use App\Ai\Domain\Entity\AiUsage;
use App\Ai\Domain\Enum\AiBudgetMode;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Notifications\Application\Dto\CostAlertResult;
use App\Notifications\Application\GenerateCostAlerts;
use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertStatus;
use App\Notifications\Domain\Enum\AlertType;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Ai\InMemoryAiBudgetRepository;
use App\Tests\Support\Ai\InMemoryAiUsageRepository;
use App\Tests\Support\Notifications\InMemoryAlertRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * Los avisos de presupuesto de IA (Fase 14).
 *
 * ## Lo que se comprueba
 *
 * - **Un aviso por mes y por situación**: la clave de deduplicación incluye el
 *   último día del mes, así que el comando se puede ejecutar cada hora sin
 *   llenar la bandeja.
 * - **El estado manda**: si el presupuesto se agota, el aviso de «se está
 *   agotando» se resuelve y aparece el de «agotado». Los dos a la vez serían
 *   ruido.
 * - **La vuelta de la situación**: si el sistema resolvió el aviso y la
 *   situación vuelve dentro del mismo mes, el aviso se reabre. Si lo descartó
 *   el usuario, no se toca: descartar es una decisión.
 */
#[CoversClass(GenerateCostAlerts::class)]
#[CoversClass(CostAlertResult::class)]
final class GenerateCostAlertsTest extends TestCase
{
    private const NOW = '2026-10-20 09:00:00';

    private InMemoryAiUsageRepository $usages;
    private InMemoryAlertRepository $alerts;
    private TenantContext $tenantContext;
    private Uuid $organizationId;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->usages = new InMemoryAiUsageRepository();
        $this->alerts = new InMemoryAlertRepository();
        $this->organizationId = Uuid::v7();
        $this->tenantContext = new TenantContext();
        $this->tenantContext->setOrganizationId($this->organizationId);
        $this->clock = new MockClock(new DateTimeImmutable(self::NOW));
    }

    public function testWithoutSpendingThereIsNoAlert(): void
    {
        $result = $this->generate();

        self::assertSame(0, $result->created);
        self::assertTrue($result->isQuiet());
        self::assertCount(0, $this->alerts->all());
    }

    public function testApproachingTheLimitCreatesAWarningAlert(): void
    {
        $this->spend(400);

        $result = $this->generate();

        self::assertSame(1, $result->created);
        self::assertTrue($result->warning);
        self::assertFalse($result->exhausted);

        $alert = $this->onlyAlert();
        self::assertSame(AlertType::AI_BUDGET_WARNING, $alert->getType());
        self::assertSame(AlertSeverity::WARNING, $alert->getSeverity());
        self::assertSame(AlertStatus::OPEN, $alert->getStatus());
        self::assertSame('ai_budget_warning|-|2026-10-31', $alert->getDedupKey());
    }

    public function testTheAlertCarriesTheNumbersOfTheBudget(): void
    {
        $this->spend(400);

        $this->generate();

        $metadata = $this->onlyAlert()->getMetadata();

        self::assertSame('2026-10', $metadata['period']);
        self::assertSame(500, $metadata['limit_minor']);
        self::assertSame(400, $metadata['spent_minor']);
        self::assertSame(80, $metadata['used_percent']);
        self::assertSame('soft', $metadata['mode']);
    }

    public function testRunningTheGeneratorTwiceDoesNotDuplicateTheAlert(): void
    {
        $this->spend(400);

        $this->generate();
        $result = $this->generate();

        self::assertSame(0, $result->created);
        self::assertSame(1, $result->unchanged);
        self::assertCount(1, $this->alerts->all());
    }

    public function testExhaustingTheBudgetReplacesTheWarningWithTheExhaustedAlert(): void
    {
        $this->spend(400);
        $this->generate();

        $this->spend(100);
        $result = $this->generate();

        self::assertSame(1, $result->created);
        self::assertSame(1, $result->resolved);
        self::assertTrue($result->exhausted);

        $alerts = $this->alerts->all();
        self::assertCount(2, $alerts);

        $warning = $this->alertOfType(AlertType::AI_BUDGET_WARNING);
        self::assertSame(AlertStatus::RESOLVED, $warning->getStatus());

        $exceeded = $this->alertOfType(AlertType::AI_BUDGET_EXCEEDED);
        self::assertSame(AlertStatus::OPEN, $exceeded->getStatus());
        self::assertSame(AlertSeverity::CRITICAL, $exceeded->getSeverity());
    }

    public function testGoingBackUnderTheLimitResolvesTheExhaustedAlert(): void
    {
        $this->spend(500);
        $this->generate();
        self::assertCount(1, $this->alerts->all());

        // El cliente sube el límite: vuelve a haber presupuesto.
        $result = $this->generate(2000);

        self::assertSame(1, $result->resolved);
        self::assertSame(0, $result->created);
        self::assertSame(AlertStatus::RESOLVED, $this->onlyAlert()->getStatus());
    }

    public function testANewMonthCreatesANewAlertAndResolvesTheOldOne(): void
    {
        $this->spend(400);
        $this->generate();

        $this->clock->sleep(86400 * 20);
        $this->spend(400);

        $result = $this->generate();

        self::assertSame(1, $result->created);
        self::assertSame(1, $result->resolved);
        self::assertCount(2, $this->alerts->all());

        $open = $this->alerts->findOpen();
        self::assertCount(1, $open);
        self::assertSame('ai_budget_warning|-|2026-11-30', $open[0]->getDedupKey());
    }

    public function testTheSituationComingBackWithinTheMonthReopensTheAlert(): void
    {
        $this->spend(400);
        $this->generate();
        self::assertSame(AlertStatus::OPEN, $this->alertOfType(AlertType::AI_BUDGET_WARNING)->getStatus());

        // El cliente sube el límite: la situación deja de darse y el aviso se
        // resuelve solo.
        $this->spend(200);
        $this->generate(1000);
        self::assertSame(AlertStatus::RESOLVED, $this->alertOfType(AlertType::AI_BUDGET_WARNING)->getStatus());

        // Y vuelve a acercarse al tope dentro del mismo mes. La clave de
        // deduplicación es única, así que no se puede crear otro aviso: el
        // que el sistema resolvió se reabre.
        $this->spend(200);
        $result = $this->generate(1000);

        self::assertSame(1, $result->reopened);
        self::assertSame(0, $result->created);
        self::assertFalse($result->isQuiet());

        $warning = $this->alertOfType(AlertType::AI_BUDGET_WARNING);
        self::assertSame(AlertStatus::OPEN, $warning->getStatus());
        self::assertNull($warning->getResolvedAt());
    }

    public function testAnAlertDismissedByTheUserIsNotReopened(): void
    {
        $this->spend(400);
        $this->generate();

        $warning = $this->alertOfType(AlertType::AI_BUDGET_WARNING);
        $warning->dismiss(Uuid::v7(), new DateTimeImmutable(self::NOW));

        // La situación deja de darse y luego vuelve: el usuario lo descartó,
        // y descartar es una decisión que el sistema no deshace.
        $this->spend(200);
        $this->generate(1000);
        $this->spend(200);
        $result = $this->generate(1000);

        self::assertSame(0, $result->reopened);
        self::assertSame(0, $result->created);
        self::assertSame(AlertStatus::DISMISSED, $warning->getStatus());
    }

    public function testTheMessageExplainsWhatHappenedAndWhatToDo(): void
    {
        $this->spend(400);

        $this->generate();

        $message = $this->onlyAlert()->getMessage();

        self::assertStringContainsString('4,00 €', $message);
        self::assertStringContainsString('5,00 €', $message);
        self::assertStringContainsString('80 %', $message);
    }

    private function generate(?int $limitMinor = null): CostAlertResult
    {
        $generator = new GenerateCostAlerts(
            budget: $this->budget($limitMinor),
            alerts: $this->alerts,
            tenantContext: $this->tenantContext,
            clock: new Clock($this->clock),
        );

        return $generator();
    }

    private function budget(?int $limitMinor = null): AiBudgetGuard
    {
        return new AiBudgetGuard(
            budgets: new InMemoryAiBudgetRepository(),
            usage: $this->usages,
            tenantContext: $this->tenantContext,
            clock: new Clock($this->clock),
            defaultLimitMinor: $limitMinor ?? 500,
            defaultMode: AiBudgetMode::SOFT,
        );
    }

    private function spend(int $costMinor): void
    {
        $this->usages->save(new AiUsage(
            organizationId: $this->organizationId,
            providerKey: 'groq',
            purpose: AiPurpose::EXTRACTION,
            tier: AiTier::CHEAP,
            costMinor: $costMinor,
            createdAt: $this->clock->now(),
        ));
    }

    private function onlyAlert(): Alert
    {
        $alerts = $this->alerts->all();
        self::assertCount(1, $alerts);

        return $alerts[0];
    }

    private function alertOfType(AlertType $type): Alert
    {
        foreach ($this->alerts->all() as $alert) {
            if ($alert->getType() === $type) {
                return $alert;
            }
        }

        self::fail('No hay ningún aviso de tipo '.$type->value.'.');
    }
}
