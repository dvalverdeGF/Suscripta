<?php

declare(strict_types=1);

namespace App\Processing\Application\Knowledge;

use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Processing\Domain\Enum\MailboxKnowledgeType;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Lo que el sistema ha aprendido de un buzón, agrupado por tipo.
 *
 * La pantalla de conocimiento no es una tabla de filas sueltas: es una lista
 * de lo que el sistema cree saber, para que el usuario pueda revisar cada
 * afirmación («este remitente es este proveedor»).
 */
final readonly class ListMailboxKnowledge
{
    public function __construct(
        private MailboxKnowledgeRepositoryInterface $entries,
    ) {
    }

    /**
     * @return array<string, list<MailboxKnowledgeEntry>> tipo => entradas
     */
    public function __invoke(Uuid $emailAccountId): array
    {
        $grouped = [];

        foreach (MailboxKnowledgeType::cases() as $type) {
            $grouped[$type->value] = $this->entries->findByType($emailAccountId, $type);
        }

        return $grouped;
    }

    /**
     * @return list<MailboxKnowledgeEntry>
     */
    public function all(Uuid $emailAccountId): array
    {
        return $this->entries->findForAccount($emailAccountId);
    }
}
