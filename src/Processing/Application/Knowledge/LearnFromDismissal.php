<?php

declare(strict_types=1);

namespace App\Processing\Application\Knowledge;

use App\Discovery\Domain\Entity\Discovery;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;

/**
 * Aprende de un descarte (ARCHITECTURE.md §13.12).
 *
 * Descartar una propuesta dice «esto no me interesa». Si el mismo remitente
 * vuelve a proponer cosas y el usuario vuelve a descartarlas, el buzón deja de
 * mirar ese remitente.
 *
 * No es lo mismo que ignorar: aquí el mensaje se ha leído y se ha descartado
 * su contenido. Ignorar es una decisión explícita del usuario en la pantalla
 * de conocimiento, y esa manda (D-53).
 */
final readonly class LearnFromDismissal
{
    public function __construct(
        private EmailMessageRepositoryInterface $messages,
        private RecordSenderRejection $recordRejection,
    ) {
    }

    public function __invoke(Discovery $discovery): void
    {
        $messageId = $discovery->getSourceEmailMessageId();

        if (null === $messageId) {
            return;
        }

        $message = $this->messages->find($messageId);

        if (null === $message) {
            return;
        }

        $sender = $message->getFromAddress();

        if (null === $sender || '' === $sender) {
            return;
        }

        ($this->recordRejection)($message->getEmailAccountId(), $message->getOrganizationId(), $sender);
    }
}
