<?php

declare(strict_types=1);

namespace App\Processing\Application\Knowledge;

use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use App\Shared\Application\Clock;

use function count;

/**
 * Poda el conocimiento del buzón (ARCHITECTURE.md §13.12).
 *
 * El conocimiento crece con el uso y no todos los patrones valen la pena. Se
 * podan dos cosas:
 *
 * - lo que **nunca se ha usado** y lleva tiempo guardado: un patrón que no
 *   casa con ningún correo no va a empezar a casar solo;
 * - lo que el usuario ya no quiere: eso lo borra el usuario, no la poda.
 *
 * Lo que sí se usa no se toca: la poda automática solo elimina Knowledge
 * sobrante, nunca lo aprendido y contrastado.
 */
final readonly class PruneMailboxKnowledge
{
    public function __construct(
        private MailboxKnowledgeRepositoryInterface $entries,
        private KnowledgeSettings $settings,
        private Clock $clock,
    ) {
    }

    /**
     * @return int número de entradas eliminadas
     */
    public function __invoke(): int
    {
        $threshold = $this->settings->staleThreshold($this->clock->now());
        $stale = $this->entries->findStale($threshold);

        foreach ($stale as $entry) {
            $this->entries->remove($entry, false);
        }

        if ([] !== $stale) {
            $this->entries->flush();
        }

        return count($stale);
    }
}
