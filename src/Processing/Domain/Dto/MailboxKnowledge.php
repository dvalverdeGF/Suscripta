<?php

declare(strict_types=1);

namespace App\Processing\Domain\Dto;

use App\Catalog\Domain\Entity\Provider;
use App\Documents\Domain\Enum\DocumentType;
use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Shared\Domain\ValueObject\BillingPeriod;
use Symfony\Component\Uid\Uuid;

/**
 * El conocimiento de un buzón aplicado a un mensaje concreto.
 *
 * Es una foto del momento: qué entradas han casado con este mensaje y qué se
 * deduce de ellas. El pipeline la consume sin volver a consultar la base de
 * datos, y las entradas que han casado son las que se anotan como usadas.
 */
final readonly class MailboxKnowledge
{
    /**
     * @param list<MailboxKnowledgeEntry> $matched entradas que han casado con el mensaje
     */
    public function __construct(
        public array $matched = [],
        public ?MailboxKnowledgeEntry $ignored = null,
        public ?MailboxKnowledgeEntry $providerEntry = null,
        public ?MailboxKnowledgeEntry $subjectEntry = null,
        public ?MailboxKnowledgeEntry $documentEntry = null,
        public ?Provider $provider = null,
        public ?DocumentType $documentType = null,
        public ?BillingPeriod $billingPeriod = null,
    ) {
    }

    /**
     * Conocimiento vacío: el buzón todavía no sabe nada.
     */
    public static function empty(): self
    {
        return new self();
    }

    /**
     * ¿El mensaje se puede saltar sin mirar su contenido?
     */
    public function isIgnored(): bool
    {
        return null !== $this->ignored && $this->ignored->excludes();
    }

    public function hasProvider(): bool
    {
        return null !== $this->provider;
    }

    public function providerId(): ?Uuid
    {
        return $this->provider?->getId();
    }

    public function providerName(): ?string
    {
        return $this->provider?->getName();
    }

    /**
     * La confianza que se hereda al proveedor conocido. Cuanto más alta, más
     * peso tiene lo aprendido frente a una identidad global.
     */
    public function confidence(): int
    {
        return $this->providerEntry?->getConfidence() ?? 0;
    }
}
