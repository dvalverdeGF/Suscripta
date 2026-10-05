<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Repository;

use App\Mailbox\Domain\Entity\MessageProcessingEvent;
use Symfony\Component\Uid\Uuid;

interface MessageProcessingEventRepositoryInterface
{
    /**
     * Historial completo de un mensaje, en orden cronológico. Es lo que se le
     * enseña al usuario cuando pregunta "¿por qué habéis propuesto esto?".
     *
     * @return list<MessageProcessingEvent>
     */
    public function findForMessage(Uuid $emailMessageId): array;

    public function save(MessageProcessingEvent $event, bool $flush = true): void;
}
