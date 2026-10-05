<?php

declare(strict_types=1);

namespace App\Processing\Application\Message;

use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Processing\Application\ProcessEmailMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Ejecuta el pipeline sobre un mensaje concreto.
 *
 * El handler es deliberadamente delgado: toda la lógica vive en
 * `ProcessEmailMessage`, que es testeable sin cola. Aquí solo se resuelve la
 * entidad y se delega.
 */
#[AsMessageHandler]
final readonly class ProcessEmailMessageHandler
{
    public function __construct(
        private EmailMessageRepositoryInterface $messages,
        private ProcessEmailMessage $processEmailMessage,
    ) {
    }

    public function __invoke(ProcessEmailMessageMessage $message): void
    {
        $emailMessage = $this->messages->find($message->emailMessageId);

        if (null === $emailMessage) {
            // El mensaje se ha borrado entre el encolado y el consumo (por
            // ejemplo, al desconectar el buzón). No es un error: no hay nada
            // que procesar y reintentar no cambiaría nada.
            return;
        }

        ($this->processEmailMessage)($emailMessage, body: $message->body);
    }
}
