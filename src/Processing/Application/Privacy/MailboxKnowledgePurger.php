<?php

declare(strict_types=1);

namespace App\Processing\Application\Privacy;

use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use App\Shared\Application\Privacy\PrivacySettings;
use App\Shared\Application\Privacy\RetentionPurgerInterface;
use DateTimeImmutable;

/**
 * Poda el conocimiento aprendido que no se ha usado en un año.
 *
 * Lo que se usa se queda: es la razón por la que el sistema reconoce al
 * remitente o el formato de la factura. Lo que lleva un año sin tocarse
 * solo ocupa sitio y confunde: si una regla dejó de servir, lo mejor es
 * que vuelva a aprenderse.
 */
final readonly class MailboxKnowledgePurger implements RetentionPurgerInterface
{
    public function __construct(
        private MailboxKnowledgeRepositoryInterface $knowledge,
        private PrivacySettings $settings,
    ) {
    }

    public function category(): string
    {
        return 'mailbox_knowledge';
    }

    public function purge(DateTimeImmutable $now): int
    {
        return $this->knowledge->pruneUnusedBefore(
            $this->settings->mailboxKnowledgeThreshold($now),
            $this->settings->purgeBatchSize,
        );
    }
}
