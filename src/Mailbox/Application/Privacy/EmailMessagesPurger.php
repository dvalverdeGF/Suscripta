<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Privacy;

use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Shared\Application\Privacy\PrivacySettings;
use App\Shared\Application\Privacy\RetentionPurgerInterface;
use DateTimeImmutable;

/**
 * Purga los metadatos de los correos que ya han caducado.
 *
 * Se purgan los **metadatos**, no el texto: el cuerpo del mensaje no se
 * guarda en el buzón (SECURITY.md §6), solo lo que hace falta para saber qué
 * se procesó.
 */
final readonly class EmailMessagesPurger implements RetentionPurgerInterface
{
    public function __construct(
        private EmailMessageRepositoryInterface $messages,
        private PrivacySettings $settings,
    ) {
    }

    public function category(): string
    {
        return 'email_messages';
    }

    public function purge(DateTimeImmutable $now): int
    {
        return $this->messages->purgeOlderThan(
            $this->settings->emailMessagesThreshold($now),
            $this->settings->purgeBatchSize,
        );
    }
}
