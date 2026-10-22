<?php

declare(strict_types=1);

namespace App\Processing\Application;

use App\Ai\Application\AiBudgetChangeHandlerInterface;
use App\Ai\Application\AiBudgetGuard;
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Processing\Application\Message\ProcessEmailMessageMessage;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;

use const DATE_ATOM;

use DateTimeImmutable;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Devuelve al pipeline los correos que quedaron aplazados por presupuesto
 * (Fase 14, D-56).
 *
 * ## Qué es un correo «aplazado»
 *
 * No es un correo fallido ni uno pendiente de revisión: es un correo **correcto
 * que no se ha podido completar** porque el presupuesto de IA se había
 * agotado. Por eso su estado (`DEFERRED`) no está entre los estados pendientes:
 * reencolarlo en cada sincronización solo gastaría ciclos para volver al
 * mismo sitio.
 *
 * ## Cuándo se sueltan
 *
 * Cuando vuelve a haber presupuesto. Eso puede pasar por dos caminos:
 *
 * 1. El usuario sube el tope en el panel de ajustes. Se sueltan en el acto,
 *    porque ha tomado una decisión y espera que pase.
 * 2. Empieza un mes nuevo, con el presupuesto renewed. Se sueltan por
 *    comando, porque no hay nadie mirando.
 *
 * ## Por qué se sueltan en bloque y no uno a uno
 *
 * Cada correo soltado es un trabajo de cola. Soltarlos de uno en uno con una
 * espera entre cada uno convertiría la liberación en un proceso de minutos.
 * Se sueltan todos de golpe y el pipeline los va cogiendo.
 */
final readonly class ReleaseDeferredMessages implements AiBudgetChangeHandlerInterface
{
    /**
     * Cuántos correos se sueltan de una vez como máximo.
     */
    private const int BATCH_SIZE = 200;

    public function __construct(
        private EmailMessageRepositoryInterface $messages,
        private MessageStateMachine $stateMachine,
        private AiBudgetGuard $budget,
        private MessageBusInterface $bus,
        private TenantContext $tenantContext,
        private Clock $clock,
    ) {
    }

    /**
     * Suelta los correos aplazados y los devuelve al pipeline.
     *
     * @return int número de correos soltados
     */
    public function __invoke(?DateTimeImmutable $now = null): int
    {
        if (!$this->budget->allowsCall()) {
            // El presupuesto sigue agotado: soltar los correos ahora solo
            // los volvería a aplazar.
            return 0;
        }

        $organizationId = $this->tenantContext->getOrganizationId();

        if (null === $organizationId) {
            return 0;
        }

        $now ??= $this->clock->now();
        $released = 0;

        foreach ($this->messages->findDeferred(self::BATCH_SIZE) as $message) {
            if (!$this->stateMachine->canTransition($message, MessageProcessingState::CANDIDATE)) {
                continue;
            }

            $this->stateMachine->transition(
                $message,
                MessageProcessingState::CANDIDATE,
                'Se retoma el análisis: vuelve a haber presupuesto de IA.',
                data: ['released_at' => $now->format(DATE_ATOM)],
            );

            $this->messages->save($message, false);
            $this->enqueue($message);

            ++$released;
        }

        if ($released > 0) {
            $this->messages->flush();
        }

        return $released;
    }

    /**
     * El presupuesto de IA se ha ampliado.
     *
     * Es la reacción que el módulo de `Ai` espera cuando el cliente sube el
     * tope: lo que estaba en espera vuelve al pipeline (D-56).
     */
    public function onBudgetRaised(): int
    {
        return $this->__invoke();
    }

    /**
     * Encola el correo para que el pipeline lo vuelva a procesar.
     */
    private function enqueue(EmailMessage $message): void
    {
        $this->bus->dispatch(
            new ProcessEmailMessageMessage($message->getId()),
            [new TransportNamesStamp(['mail_processing'])],
        );
    }
}
