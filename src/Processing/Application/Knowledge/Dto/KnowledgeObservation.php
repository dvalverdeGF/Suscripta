<?php

declare(strict_types=1);

namespace App\Processing\Application\Knowledge\Dto;

use App\Documents\Domain\Enum\DocumentType;
use App\Processing\Domain\Enum\MailboxKnowledgeSource;
use App\Shared\Domain\ValueObject\BillingPeriod;
use Symfony\Component\Uid\Uuid;

/**
 * Lo que se ha observado en un mensaje y merece recordarse.
 *
 * Es el mismo dato con dos orígenes: lo que el pipeline ha deducido al
 * procesar el correo (`OBSERVED`) y lo que el usuario ha confirmado o
 * corregido (`CONFIRMATION`, `USER`). La diferencia importa: lo deducido solo
 * rellena huecos, lo afirmado por el usuario **pisa** lo anterior.
 */
final readonly class KnowledgeObservation
{
    /**
     * @param list<string> $attachmentNames
     */
    public function __construct(
        public Uuid $emailAccountId,
        public Uuid $organizationId,
        public ?string $sender = null,
        public ?string $senderDomain = null,
        public ?string $subject = null,
        public array $attachmentNames = [],
        public ?Uuid $providerId = null,
        public ?DocumentType $documentType = null,
        public ?BillingPeriod $billingPeriod = null,
        public MailboxKnowledgeSource $source = MailboxKnowledgeSource::OBSERVED,
        public ?int $confidence = null,
    ) {
    }

    /**
     * ¿El usuario se ha hecho cargo de este dato?
     */
    public function isAuthoritative(): bool
    {
        return $this->source->isUserAuthored();
    }
}
