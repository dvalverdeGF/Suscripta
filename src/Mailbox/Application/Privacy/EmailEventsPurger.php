<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Privacy;

use App\Mailbox\Domain\Repository\MessageProcessingEventRepositoryInterface;
use App\Shared\Application\Privacy\PrivacySettings;
use App\Shared\Application\Privacy\RetentionPurgerInterface;
use DateTimeImmutable;

/**
 * Purga los eventos de procesamiento de los correos caducados.
 *
 * Cada evento dice qué extractor pasó por un mensaje y cuánto tardó. Si el
 * mensaje ya no está, su historia solo ocupa sitio: se purga con él.
 */
final readonly class EmailEventsPurger implements RetentionPurgerInterface
{
    public function __construct(
        private MessageProcessingEventRepositoryInterface $events,
        private PrivacySettings $settings,
    ) {
    }

    public function category(): string
    {
        return 'email_events';
    }

    public function purge(DateTimeImmutable $now): int
    {
        return $this->events->purgeOlderThan(
            $this->settings->emailEventsThreshold($now),
            $this->settings->purgeBatchSize,
        );
    }
}
