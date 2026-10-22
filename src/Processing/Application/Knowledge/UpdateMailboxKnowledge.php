<?php

declare(strict_types=1);

namespace App\Processing\Application\Knowledge;

use App\Processing\Application\Knowledge\Dto\KnowledgeEdit;
use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use App\Shared\Application\Clock;

/**
 * El usuario corrige lo aprendido (ARCHITECTURE.md §13.12).
 *
 * Cuando el usuario edita una entrada, el dato pasa a ser suyo: la confianza
 * sube a la que ha pedido y la fuente queda marcada como edición manual, para
 * que se distinguishable de lo deducido por el sistema.
 */
final readonly class UpdateMailboxKnowledge
{
    public function __construct(
        private MailboxKnowledgeRepositoryInterface $entries,
        private Clock $clock,
    ) {
    }

    public function __invoke(MailboxKnowledgeEntry $entry, KnowledgeEdit $edit): MailboxKnowledgeEntry
    {
        $entry->edit(
            value: $edit->value,
            providerId: $edit->providerId,
            documentType: $edit->documentType,
            billingPeriod: $edit->billingPeriod,
            confidence: $edit->confidence,
            at: $this->clock->now(),
        );

        $this->entries->save($entry);

        return $entry;
    }
}
