<?php

declare(strict_types=1);

namespace App\Processing\Application\Knowledge;

use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;

/**
 * El usuario borra lo aprendido (ARCHITECTURE.md §13.12).
 *
 * Borrar es la operación más simple y la más importante: el conocimiento del
 * buzón es del usuario, y si algo está mal, la respuesta tiene que ser
 * «quítalo», no «espera a que se pods».
 */
final readonly class DeleteMailboxKnowledge
{
    public function __construct(
        private MailboxKnowledgeRepositoryInterface $entries,
    ) {
    }

    public function __invoke(MailboxKnowledgeEntry $entry): void
    {
        $this->entries->remove($entry);
    }
}
