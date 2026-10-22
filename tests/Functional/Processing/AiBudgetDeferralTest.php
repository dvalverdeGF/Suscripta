<?php

declare(strict_types=1);

namespace App\Tests\Functional\Processing;

use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Mailbox\Domain\Repository\MessageProcessingEventRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Processing\DeferralScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El camino de aplazamiento por presupuesto (Fase 14, D-56 y D-57).
 *
 * ## La diferencia que importa
 *
 * Un correo que necesita IA y se queda sin presupuesto **no está mal**: está
 * esperando. Por eso su estado es `DEFERRED` y no `REQUIRES_REVIEW`.
 *
 * - `REQUIRES_REVIEW` espera a una persona: nadie lo devolverá al pipeline
 *   solo, y llenaría la bandeja de cosas que en realidad solo necesitan
 *   tiempo.
 * - `DEFERRED` espera a presupuesto: en cuanto vuelve a haber, el correo
 *   vuelve al pipeline por su cuenta.
 *
 * Esta prueba recorre el pipeline completo contra la base de datos real: se
 * agota el presupuesto, se procesa un correo que necesita IA, se comprueba que
 * queda aplazado (y no en revisión manual) y se comprueba que al ampliar el
 * presupuesto vuelve al pipeline.
 */
final class AiBudgetDeferralTest extends WebTestCase
{
    use DeferralScenario;

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');

        $memberships = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($this->user);

        self::assertNotEmpty($memberships);

        $this->organizationId = $memberships[0]['organization']->getId();
    }

    public function testAMessageThatNeedsAiIsDeferredWhenTheBudgetIsExhausted(): void
    {
        $account = $this->createAccount();
        $message = $this->createInvoicelikeMessage($account);

        $this->exhaustTheBudget();
        $this->stubImapWithInvoice();

        $this->runPipeline($message);

        $stored = $this->reload($message);

        self::assertSame(
            MessageProcessingState::DEFERRED,
            $stored->getProcessingState(),
            'Un correo sin presupuesto debe esperar, no pasar a revisión manual.',
        );

        self::assertNull($stored->getProcessedAt(), 'Un correo aplazado no ha terminado.');
        self::assertCount(0, $this->discoveries(), 'Un correo aplazado no genera ninguna propuesta.');

        // El motivo queda escrito: sin él no se puede explicar después por
        // qué el correo no se ha analizado.
        $deferred = null;

        foreach ($this->events($message) as $event) {
            if (MessageProcessingState::DEFERRED === $event->getToState()) {
                $deferred = $event;
            }
        }

        self::assertNotNull($deferred);
        self::assertSame('budget_exhausted', $deferred->getData()['skip_reason'] ?? null);
    }

    public function testADeferredMessageGoesBackToThePipelineWhenTheBudgetIsRaised(): void
    {
        $account = $this->createAccount();
        $message = $this->createInvoicelikeMessage($account);

        $this->exhaustTheBudget();
        $this->stubImapWithInvoice();
        $this->runPipeline($message);

        self::assertSame(MessageProcessingState::DEFERRED, $this->reload($message)->getProcessingState());

        // El cliente amplía el presupuesto: lo que estaba en espera vuelve.
        $released = $this->raiseTheBudget();

        self::assertSame(1, $released);
        self::assertSame(MessageProcessingState::CANDIDATE, $this->reload($message)->getProcessingState());
    }

    public function testADeferredMessageIsNotPickedUpByThePendingSweep(): void
    {
        $account = $this->createAccount();
        $message = $this->createInvoicelikeMessage($account);

        $this->exhaustTheBudget();
        $this->stubImapWithInvoice();
        $this->runPipeline($message);

        // `DEFERRED` no es un estado pendiente: reencolarlo en cada
        // sincronización solo gastaría ciclos para volver al mismo sitio.
        self::assertFalse(MessageProcessingState::DEFERRED->isPending());
        self::assertFalse(MessageProcessingState::DEFERRED->isTerminal());
    }

    /**
     * @return list<\App\Discovery\Domain\Entity\Discovery>
     */
    private function discoveries(): array
    {
        $discoveries = self::getContainer()->get(DiscoveryRepositoryInterface::class);
        $tenantContext = self::getContainer()->get(TenantContext::class);

        return $tenantContext->runAs(
            $this->organizationId,
            static fn (): array => $discoveries->findForOrganization(),
        );
    }

    /**
     * @return list<\App\Mailbox\Domain\Entity\MessageProcessingEvent>
     */
    private function events(EmailMessage $message): array
    {
        return self::getContainer()->get(MessageProcessingEventRepositoryInterface::class)
            ->findForMessage($message->getId());
    }
}
