<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Repository;

use App\Mailbox\Domain\Entity\MessageProcessingEvent;
use DateTimeImmutable;
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

    /**
     * Borra los eventos de procesamiento de los mensajes de una cuenta.
     *
     * @return int número de eventos eliminados
     */
    public function removeForAccount(Uuid $emailAccountId): int;

    /**
     * Borra los eventos de procesamiento de los mensajes de una organización.
     *
     * @return int número de eventos eliminados
     */
    public function removeForOrganization(Uuid $organizationId): int;

    /**
     * Purga los eventos más antiguos que el umbral indicado.
     *
     * @return int número de eventos eliminados
     */
    public function purgeOlderThan(DateTimeImmutable $threshold, int $limit = 500): int;
}
