<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Application;

use App\Ai\Application\AiBudgetGuard;
use App\Ai\Application\AiCostCalculator;
use App\Ai\Application\Dto\AiCostReport;
use App\Ai\Domain\Entity\AiUsage;
use App\Ai\Domain\Enum\AiBudgetMode;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Ai\InMemoryAiBudgetRepository;
use App\Tests\Support\Ai\InMemoryAiUsageRepository;
use App\Tests\Support\Mailbox\InMemoryEmailMessageRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * El informe de coste de la IA (Fase 14).
 *
 * El panel cruza dos fuentes que viven en tablas distintas: el libro mayor de
 * uso (`ai_usage`) y los correos procesados (`email_message`). Aquí se
 * comprueba el cruce y, sobre todo, los porcentajes, que es donde se cuelan
 * los errores: son el número que el operador mira para decidir.
 *
 * ## El detalle que más se confunde
 *
 * Hay **dos** enums llamados «nivel» y no son lo mismo:
 *
 * - `AiTier` (`cheap` / `advanced`): el nivel de la **llamada** a IA.
 * - `ExtractionTier` (`deterministic`, `ai_cheap`, …): el nivel con el que se
 *   **resolvió el correo**.
 *
 * El porcentaje de escalado cuenta llamadas avanzadas sobre llamadas
 * totales; el porcentaje de resolución sin IA cuenta correos resueltos por
 * reglas sobre correos totales. Con la clave equivocada, el primero sale
 * siempre `0` y el panel miente sin que nada falle.
 */
#[CoversClass(AiCostCalculator::class)]
#[CoversClass(AiCostReport::class)]
final class AiCostCalculatorTest extends TestCase
{
    private const NOW = '2026-10-20 09:00:00';

    private InMemoryAiUsageRepository $usages;
    private InMemoryEmailMessageRepository $messages;
    private TenantContext $tenantContext;
    private Uuid $organizationId;
    private Uuid $accountId;

    protected function setUp(): void
    {
        $this->usages = new InMemoryAiUsageRepository();
        $this->messages = new InMemoryEmailMessageRepository();
        $this->organizationId = Uuid::v7();
        $this->accountId = Uuid::v7();
        $this->tenantContext = new TenantContext();
        $this->tenantContext->setOrganizationId($this->organizationId);
    }

    public function testTheReportCrossesTheLedgerAndTheProcessedMessages(): void
    {
        $this->spend(cost: 30, tier: AiTier::CHEAP, provider: 'groq', purpose: AiPurpose::EXTRACTION);
        $this->spend(cost: 70, tier: AiTier::ADVANCED, provider: 'groq', purpose: AiPurpose::EXTRACTION);
        $this->spend(cost: 20, tier: AiTier::CHEAP, provider: 'openai', purpose: AiPurpose::SUMMARIZATION);

        $this->message(ExtractionTier::DETERMINISTIC);
        $this->message(ExtractionTier::DETERMINISTIC);
        $this->message(ExtractionTier::AI_CHEAP);
        $this->message(ExtractionTier::AI_ADVANCED);

        $report = $this->calculator()->currentMonth();

        self::assertSame(120, $report->totalCostMinor);
        self::assertSame(3, $report->calls);
        self::assertSame(4, $report->messagesTotal);
        self::assertSame(4000, $report->averageProcessingMs);
        self::assertSame(['groq' => 100, 'openai' => 20], $report->costByProvider);
        self::assertSame([
            AiPurpose::EXTRACTION->value => 100,
            AiPurpose::SUMMARIZATION->value => 20,
        ], $report->costByPurpose);
    }

    public function testTheEscalationRateCountsTheAdvancedCalls(): void
    {
        $this->spend(cost: 10, tier: AiTier::CHEAP);
        $this->spend(cost: 10, tier: AiTier::CHEAP);
        $this->spend(cost: 10, tier: AiTier::CHEAP);
        $this->spend(cost: 10, tier: AiTier::ADVANCED);

        $report = $this->calculator()->currentMonth();

        // Una de cada cuatro llamadas fue al nivel caro.
        self::assertSame(25, $report->escalationRatePercent());
        self::assertSame(1, $report->callsByTier[AiTier::ADVANCED->value]);
        self::assertSame(3, $report->callsByTier[AiTier::CHEAP->value]);
    }

    public function testTheEscalationRateIsZeroWithoutCalls(): void
    {
        $report = $this->calculator()->currentMonth();

        self::assertSame(0, $report->escalationRatePercent());
        self::assertSame(0, $report->averageCostPerCallMinor());
        self::assertSame(0, $report->failureRatePercent());
        self::assertTrue($report->isEmpty());
    }

    public function testTheShareResolvedWithoutAiCountsTheRuleTiers(): void
    {
        $this->message(ExtractionTier::DETERMINISTIC);
        $this->message(ExtractionTier::DETERMINISTIC);
        $this->message(ExtractionTier::KNOWN_PARSER);
        $this->message(ExtractionTier::AI_CHEAP);
        $this->message(ExtractionTier::AI_ADVANCED);

        $report = $this->calculator()->currentMonth();

        // Tres de cinco correos los resolvieron las reglas.
        self::assertSame(60, $report->resolvedWithoutAiPercent());
    }

    public function testTheAverageCostPerCallIsComputedOverTheCalls(): void
    {
        $this->spend(cost: 10, tier: AiTier::CHEAP);
        $this->spend(cost: 20, tier: AiTier::CHEAP);
        $this->spend(cost: 33, tier: AiTier::CHEAP);

        $report = $this->calculator()->currentMonth();

        self::assertSame(21, $report->averageCostPerCallMinor());
    }

    public function testTheFailureRateCountsTheFailedCalls(): void
    {
        $this->spend(cost: 10, tier: AiTier::CHEAP);
        $this->spend(cost: 10, tier: AiTier::CHEAP, successful: false);
        $this->spend(cost: 10, tier: AiTier::CHEAP, successful: false);
        $this->spend(cost: 10, tier: AiTier::CHEAP);

        $report = $this->calculator()->currentMonth();

        self::assertSame(2, $report->failures);
        self::assertSame(50, $report->failureRatePercent());
    }

    public function testTheDeferredMessagesAreCountedApartFromThePeriod(): void
    {
        $this->message(ExtractionTier::AI_CHEAP);
        $this->deferredMessage();

        $report = $this->calculator()->currentMonth();

        // El correo aplazado no cuenta como procesado, pero sí como pendiente
        // de presupuesto: es la explicación de por qué el gasto se queda
        // por debajo del tope.
        self::assertSame(1, $report->messagesTotal);
        self::assertSame(1, $report->deferredMessages);
    }

    public function testTheReportWithoutAnOrganizationIsEmpty(): void
    {
        $this->spend(cost: 100, tier: AiTier::CHEAP);

        $tenantContext = new TenantContext();
        $calculator = new AiCostCalculator(
            usage: $this->usages,
            messages: $this->messages,
            budget: $this->guard(),
            tenantContext: $tenantContext,
            clock: new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );

        $report = $calculator->currentMonth();

        self::assertSame(0, $report->totalCostMinor);
        self::assertSame(0, $report->calls);
        self::assertTrue($report->isEmpty());
    }

    public function testTheReportCarriesTheBudgetStatus(): void
    {
        $this->spend(cost: 400, tier: AiTier::CHEAP);

        $report = $this->calculator(limitMinor: 500)->currentMonth();

        self::assertSame(500, $report->budget->limitMinor);
        self::assertSame(400, $report->budget->spentMinor);
        self::assertTrue($report->budget->warning);
        self::assertFalse($report->budget->exhausted);
    }

    public function testThePeriodIsTheNaturalMonth(): void
    {
        $period = $this->calculator()->currentPeriod();

        self::assertSame('2026-10-01 00:00:00', $period->from->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-01 00:00:00', $period->to?->format('Y-m-d H:i:s'));
    }

    private function calculator(int $limitMinor = 500): AiCostCalculator
    {
        return new AiCostCalculator(
            usage: $this->usages,
            messages: $this->messages,
            budget: $this->guard($limitMinor),
            tenantContext: $this->tenantContext,
            clock: new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );
    }

    private function guard(int $limitMinor = 500): AiBudgetGuard
    {
        return new AiBudgetGuard(
            budgets: new InMemoryAiBudgetRepository(),
            usage: $this->usages,
            tenantContext: $this->tenantContext,
            clock: new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
            defaultLimitMinor: $limitMinor,
            defaultMode: AiBudgetMode::SOFT,
        );
    }

    private function spend(
        int $cost,
        AiTier $tier,
        string $provider = 'groq',
        AiPurpose $purpose = AiPurpose::EXTRACTION,
        bool $successful = true,
    ): void {
        $this->usages->save(new AiUsage(
            organizationId: $this->organizationId,
            providerKey: $provider,
            purpose: $purpose,
            tier: $tier,
            costMinor: $cost,
            successful: $successful,
            durationMs: 100,
            createdAt: new DateTimeImmutable(self::NOW),
        ));
    }

    private function message(ExtractionTier $tier): void
    {
        $message = new EmailMessage($this->organizationId, $this->accountId, 'INBOX', 1);
        $message->applyMetadata(
            messageId: '<'.spl_object_id($message).'@example.com>',
            fromAddress: 'facturas@example.com',
            fromName: 'Facturas',
            replyTo: null,
            senderDomain: 'example.com',
            toAddresses: ['cliente@example.com'],
            subject: 'Factura',
            receivedAt: new DateTimeImmutable('2026-10-10 10:00:00'),
            sizeBytes: 1000,
            contentType: 'text/plain',
            attachmentNames: [],
            attachmentTypes: [],
        );
        $message->recordExtraction($tier, 'test');
        $message->markProcessed(new DateTimeImmutable('2026-10-10 10:00:04'));
        $message->setProcessingState(MessageProcessingState::EXTRACTED);

        $this->messages->save($message);
    }

    private function deferredMessage(): void
    {
        $message = new EmailMessage($this->organizationId, $this->accountId, 'INBOX', 2);
        $message->applyMetadata(
            messageId: '<aplazado@example.com>',
            fromAddress: 'facturas@example.com',
            fromName: 'Facturas',
            replyTo: null,
            senderDomain: 'example.com',
            toAddresses: ['cliente@example.com'],
            subject: 'Factura',
            receivedAt: new DateTimeImmutable('2026-10-11 10:00:00'),
            sizeBytes: 1000,
            contentType: 'text/plain',
            attachmentNames: [],
            attachmentTypes: [],
        );
        $message->setProcessingState(MessageProcessingState::DEFERRED);

        $this->messages->save($message);
    }
}
