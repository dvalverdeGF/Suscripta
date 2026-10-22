<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application;

use App\Ai\Application\AiBudgetGuard;
use App\Ai\Domain\Entity\AiUsage;
use App\Ai\Domain\Enum\AiBudgetMode;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Processing\Application\MessageStateMachine;
use App\Processing\Application\ReleaseDeferredMessages;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Ai\InMemoryAiBudgetRepository;
use App\Tests\Support\Ai\InMemoryAiUsageRepository;
use App\Tests\Support\Mailbox\InMemoryEmailMessageRepository;
use App\Tests\Support\Mailbox\InMemoryMessageProcessingEventRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * La liberación de los correos aplazados (Fase 14, D-56).
 *
 * Un correo aplazado (`DEFERRED`) no está pendiente: está **esperando
 * presupuesto**. Por eso no se reencola en cada sincronización, sino solo
 * cuando vuelve a haber presupuesto: al subir el tope o al empezar un mes
 * nuevo.
 *
 * ## Lo que se comprueba
 *
 * - Con el presupuesto agotado no se suelta nada (soltar solo los volvería a
 *   aplazar).
 * - Con presupuesto se sueltan, vuelven al estado `CANDIDATE` y se encolan.
 * - Es idempotente: llamarlo dos veces no suelta dos veces el mismo correo.
 */
#[CoversClass(ReleaseDeferredMessages::class)]
final class ReleaseDeferredMessagesTest extends TestCase
{
    private const NOW = '2026-10-20 09:00:00';

    private InMemoryEmailMessageRepository $messages;
    private InMemoryMessageProcessingEventRepository $events;
    private InMemoryAiUsageRepository $usages;
    private TenantContext $tenantContext;
    private Uuid $organizationId;
    private MockClock $clock;
    private MessageBusInterface $bus;
    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        $this->messages = new InMemoryEmailMessageRepository();
        $this->events = new InMemoryMessageProcessingEventRepository();
        $this->usages = new InMemoryAiUsageRepository();
        $this->organizationId = Uuid::v7();
        $this->tenantContext = new TenantContext();
        $this->tenantContext->setOrganizationId($this->organizationId);
        $this->clock = new MockClock(new DateTimeImmutable(self::NOW));
        $this->dispatched = [];

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            function (object $message): Envelope {
                $this->dispatched[] = $message;

                return new Envelope($message);
            },
        );
        $this->bus = $bus;
    }

    public function testWithoutDeferredMessagesNothingIsReleased(): void
    {
        $released = ($this->service())();

        self::assertSame(0, $released);
        self::assertCount(0, $this->dispatched);
    }

    public function testTheDeferredMessagesGoBackToThePipeline(): void
    {
        $message = $this->deferredMessage();

        $released = ($this->service())();

        self::assertSame(1, $released);
        self::assertSame(MessageProcessingState::CANDIDATE, $message->getProcessingState());
        self::assertCount(1, $this->dispatched);
    }

    public function testTheReleaseIsRecordedInTheHistory(): void
    {
        $message = $this->deferredMessage();

        ($this->service())();

        $history = $this->events->findForMessage($message->getId());
        self::assertCount(1, $history);
        self::assertSame(MessageProcessingState::DEFERRED, $history[0]->getFromState());
        self::assertSame(MessageProcessingState::CANDIDATE, $history[0]->getToState());
        self::assertArrayHasKey('released_at', $history[0]->getData());
    }

    public function testAnExhaustedBudgetReleasesNothing(): void
    {
        $this->deferredMessage();
        $this->spend(500);

        $released = ($this->service(limitMinor: 500, mode: AiBudgetMode::HARD))();

        // Soltar los correos ahora solo los volvería a aplazar.
        self::assertSame(0, $released);
        self::assertCount(0, $this->dispatched);
    }

    public function testARaisedBudgetReleasesTheMessages(): void
    {
        $message = $this->deferredMessage();
        $this->spend(500);

        // El cliente sube el tope: ahora sí hay presupuesto.
        $released = ($this->service(limitMinor: 5000, mode: AiBudgetMode::HARD))();

        self::assertSame(1, $released);
        self::assertSame(MessageProcessingState::CANDIDATE, $message->getProcessingState());
        self::assertCount(1, $this->dispatched);
    }

    public function testReleasingTwiceDoesNotReleaseTwice(): void
    {
        $this->deferredMessage();

        self::assertSame(1, ($this->service())());
        self::assertSame(0, ($this->service())());
        self::assertCount(1, $this->dispatched);
    }

    public function testWithoutAnOrganizationNothingIsReleased(): void
    {
        $this->deferredMessage();

        $tenantContext = new TenantContext();
        $service = new ReleaseDeferredMessages(
            messages: $this->messages,
            stateMachine: $this->stateMachine(),
            budget: $this->guard($tenantContext, 500, AiBudgetMode::SOFT),
            bus: $this->bus,
            tenantContext: $tenantContext,
            clock: new Clock($this->clock),
        );

        self::assertSame(0, $service());
        self::assertCount(0, $this->dispatched);
    }

    public function testTheBudgetHandlerReleasesTheMessages(): void
    {
        $message = $this->deferredMessage();

        // La vía por la que el módulo de IA avisa de que el presupuesto ha
        // cambiado, sin que `Processing` tenga que conocer al guardián.
        $released = $this->service()->onBudgetRaised();

        self::assertSame(1, $released);
        self::assertSame(MessageProcessingState::CANDIDATE, $message->getProcessingState());
    }

    private function service(
        int $limitMinor = 500,
        AiBudgetMode $mode = AiBudgetMode::SOFT,
    ): ReleaseDeferredMessages {
        $service = new ReleaseDeferredMessages(
            messages: $this->messages,
            stateMachine: $this->stateMachine(),
            budget: $this->guard($this->tenantContext, $limitMinor, $mode),
            bus: $this->bus,
            tenantContext: $this->tenantContext,
            clock: new Clock($this->clock),
        );

        return $service;
    }

    private function stateMachine(): MessageStateMachine
    {
        return new MessageStateMachine(
            events: $this->events,
            clock: new Clock($this->clock),
        );
    }

    private function guard(
        TenantContext $tenantContext,
        int $limitMinor,
        AiBudgetMode $mode,
    ): AiBudgetGuard {
        return new AiBudgetGuard(
            budgets: new InMemoryAiBudgetRepository(),
            usage: $this->usages,
            tenantContext: $tenantContext,
            clock: new Clock($this->clock),
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
            costMinor: $costMinor,
            createdAt: $this->clock->now(),
        ));
    }

    private function deferredMessage(): EmailMessage
    {
        $message = new EmailMessage($this->organizationId, Uuid::v7(), 'INBOX', 1);
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

        return $message;
    }
}
