<?php

declare(strict_types=1);

namespace App\Processing\Application\Knowledge;

use App\Mailbox\Application\MailboxKnowledgeCleanerInterface;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Borra el conocimiento aprendido de un buzón concreto.
 *
 * Es la implementación del puerto que declara el módulo de buzón: cuando el
 * usuario desconecta o elimina un buzón, lo aprendido sobre ese buzón se va
 * con él. Lo aprendido de **otros** buzones no se toca: puede que el mismo
 * remitente llegue por otra vía.
 */
final readonly class ClearMailboxKnowledge implements MailboxKnowledgeCleanerInterface
{
    public function __construct(
        private MailboxKnowledgeRepositoryInterface $knowledge,
    ) {
    }

    public function removeForAccount(Uuid $emailAccountId): int
    {
        return $this->knowledge->removeForAccount($emailAccountId);
    }
}
