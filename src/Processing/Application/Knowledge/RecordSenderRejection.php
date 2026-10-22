<?php

declare(strict_types=1);

namespace App\Processing\Application\Knowledge;

use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Processing\Domain\Enum\MailboxKnowledgeSource;
use App\Processing\Domain\Enum\MailboxKnowledgeType;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use App\Shared\Application\Clock;

use function mb_strtolower;
use function min;

use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * Aprende de un descarte del usuario (ARCHITECTURE.md §13.12).
 *
 * Cuando el usuario descarta una propuesta, el sistema anota que ese remitente
 * no ha servido. **Un solo descarte no basta para ignorar al remitente**: el
 * usuario puede descartar una factura suelta y seguir queriendo las demás. Por
 * eso la confianza sube de treinta en treinta y solo a partir de sesenta
 * (dos descartes) el remitente se salta los mensajes.
 *
 * El usuario siempre puede ir a la pantalla de conocimiento y ajustar el
 * valor a mano, que es lo que fija la confianza de verdad.
 */
final readonly class RecordSenderRejection
{
    /**
     * Cuánta confianza gana el remitente con cada descarte.
     */
    private const int REJECTION_STEP = 30;

    public function __construct(
        private MailboxKnowledgeRepositoryInterface $entries,
        private Clock $clock,
    ) {
    }

    public function __invoke(Uuid $emailAccountId, Uuid $organizationId, string $sender): void
    {
        $sender = mb_strtolower(trim($sender));

        if ('' === $sender) {
            return;
        }

        $now = $this->clock->now();
        $existing = $this->entries->findByValue($emailAccountId, MailboxKnowledgeType::IGNORED_SENDER, $sender);

        if (null === $existing) {
            $this->entries->save(new MailboxKnowledgeEntry(
                emailAccountId: $emailAccountId,
                organizationId: $organizationId,
                type: MailboxKnowledgeType::IGNORED_SENDER,
                value: $sender,
                source: MailboxKnowledgeSource::OBSERVED,
                confidence: self::REJECTION_STEP,
                now: $now,
            ));

            return;
        }

        $existing->reinforce(min(100, $existing->getConfidence() + self::REJECTION_STEP), $now);
        $this->entries->save($existing);
    }
}
