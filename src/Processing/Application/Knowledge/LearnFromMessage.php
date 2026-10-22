<?php

declare(strict_types=1);

namespace App\Processing\Application\Knowledge;

use App\Documents\Domain\Enum\DocumentType;
use App\Processing\Application\Knowledge\Dto\KnowledgeObservation;
use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Processing\Domain\Enum\MailboxKnowledgeType;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Domain\ValueObject\BillingPeriod;

use function array_slice;

use DateTimeImmutable;

use function mb_strlen;
use function mb_strtolower;
use function mb_substr;

use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * Aprende del buzón (ARCHITECTURE.md §13.12).
 *
 * Escribe lo que el sistema ha observado en un mensaje para que los siguientes
 * correos del mismo remitente se resuelvan sin pagar IA. De una observación se
 * guardan, como mucho, cuatro cosas:
 *
 * - **remitente → proveedor**: la más valiosa. Con ella el nombre del
 *   proveedor es el correcto y no hace falta adivinarlo ni pagarlo.
 * - **dominio → proveedor**: la misma información pero más floja; cubre el
 *   caso en que el proveedor cambia de dirección de envío.
 * - **plantilla de asunto → tipo de documento**: permite reconocer una factura
 *   sin leerla.
 * - **plantilla de adjunto → tipo de documento**: lo mismo, pero sobre el
 *   archivo, que es más estable que el asunto.
 *
 * **No se aprende de cualquier mensaje.** El remitente y el dominio solo se
 * aprenden cuando hay un proveedor conocido detrás, porque una dirección sin
 * proveedor no identifica a nadie. Las plantillas de asunto y de adjunto solo
 * se aprenden cuando el tipo de documento es distinto de «otro»: aprender de
 * un documento mal leído es la forma más rápida de contaminar el buzón.
 */
final readonly class LearnFromMessage
{
    /**
     * Número máximo de adjuntos cuya plantilla se aprende. Un correo con
     * quince adjuntos no aporta quince patrones, sino ruido.
     */
    private const int MAX_ATTACHMENT_PATTERNS = 3;

    public function __construct(
        private MailboxKnowledgeRepositoryInterface $entries,
        private Clock $clock,
    ) {
    }

    public function __invoke(KnowledgeObservation $observation): void
    {
        $now = $this->clock->now();
        $confidence = $observation->confidence ?? $observation->source->defaultConfidence();
        $learned = [];

        $providerId = $observation->providerId;
        $documentType = $observation->documentType;
        $billingPeriod = $observation->billingPeriod;

        if (null !== $providerId) {
            $sender = $this->normalize($observation->sender);

            if (null !== $sender) {
                $learned[] = $this->upsert(
                    $observation,
                    MailboxKnowledgeType::SENDER_MAPPING,
                    $sender,
                    $providerId,
                    $documentType,
                    $billingPeriod,
                    $confidence,
                    $now,
                );
            }

            $domain = $this->normalize($observation->senderDomain);

            if (null !== $domain) {
                $learned[] = $this->upsert(
                    $observation,
                    MailboxKnowledgeType::PROVIDER_HINT,
                    $domain,
                    $providerId,
                    null,
                    null,
                    $confidence,
                    $now,
                );
            }
        }

        if (null !== $documentType && DocumentType::OTHER !== $documentType) {
            $subject = trim($observation->subject ?? '');

            if ('' !== $subject) {
                $pattern = PatternTemplate::build($subject);

                if (null !== $pattern) {
                    $learned[] = $this->upsert(
                        $observation,
                        MailboxKnowledgeType::SUBJECT_PATTERN,
                        $pattern,
                        null,
                        $documentType,
                        null,
                        $confidence,
                        $now,
                    );
                }
            }

            foreach (array_slice($observation->attachmentNames, 0, self::MAX_ATTACHMENT_PATTERNS) as $name) {
                $pattern = PatternTemplate::build($name);

                if (null === $pattern) {
                    continue;
                }

                $learned[] = $this->upsert(
                    $observation,
                    MailboxKnowledgeType::DOCUMENT_PATTERN,
                    $pattern,
                    null,
                    $documentType,
                    null,
                    $confidence,
                    $now,
                );
            }
        }

        $this->entries->saveAll($learned);
    }

    /**
     * Crea la entrada si no existe y la refuerza si ya existe.
     */
    private function upsert(
        KnowledgeObservation $observation,
        MailboxKnowledgeType $type,
        string $value,
        ?Uuid $providerId,
        ?DocumentType $documentType,
        ?BillingPeriod $billingPeriod,
        int $confidence,
        DateTimeImmutable $now,
    ): MailboxKnowledgeEntry {
        $existing = $this->entries->findByValue($observation->emailAccountId, $type, $value);

        if (null === $existing) {
            $entry = new MailboxKnowledgeEntry(
                emailAccountId: $observation->emailAccountId,
                organizationId: $observation->organizationId,
                type: $type,
                value: $value,
                providerId: $providerId,
                documentType: $documentType,
                billingPeriod: $billingPeriod,
                source: $observation->source,
                confidence: $confidence,
                now: $now,
            );

            $this->entries->save($entry, false);

            return $entry;
        }

        $existing->learn(
            providerId: $providerId,
            documentType: $documentType,
            billingPeriod: $billingPeriod,
            confidence: $confidence,
            authoritative: $observation->isAuthoritative(),
            at: $now,
        );

        $this->entries->save($existing, false);

        return $existing;
    }

    private function normalize(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = mb_strtolower(trim($value));

        if ('' === $value) {
            return null;
        }

        // Un remitente absurdamente largo no es un remitente: es un texto mal
        // colocado. Se recorta en vez de guardarlo.
        return 255 < mb_strlen($value) ? mb_substr($value, 0, 255) : $value;
    }
}
