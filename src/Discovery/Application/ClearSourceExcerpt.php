<?php

declare(strict_types=1);

namespace App\Discovery\Application;

use App\Discovery\Domain\Entity\Discovery;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;

/**
 * Borra el extracto del cuerpo del correo que originó una propuesta.
 *
 * El extracto existe **solo mientras hay una propuesta pendiente de revisar**
 * (ver `EmailMessage::$bodyExcerpt`): es lo que permite al usuario ver de
 * qué salió la propuesta sin abrir el correo. En cuanto la propuesta está
 * resuelta, ese texto es el cuerpo de un correo de un tercero: dejarlo ahí
 * sería guardar más dato del necesario, y el documento que se queda ya está
 * en su propio módulo.
 *
 * Se llama al confirmar y al descartar: en los dos casos la propuesta deja de
 * estar pendiente.
 */
final readonly class ClearSourceExcerpt
{
    public function __construct(
        private EmailMessageRepositoryInterface $messages,
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

        if (null === $message->getBodyExcerpt()) {
            return;
        }

        $message->setBodyExcerpt(null);
        $this->messages->save($message);
    }
}
