<?php

declare(strict_types=1);

namespace App\Processing\Application\Knowledge;

use App\Documents\Domain\Enum\DocumentType;
use App\Processing\Application\Knowledge\Dto\KnowledgeEdit;
use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Processing\Domain\Enum\MailboxKnowledgeSource;
use App\Processing\Domain\Enum\MailboxKnowledgeType;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Domain\ValueObject\BillingPeriod;
use Symfony\Component\Uid\Uuid;

/**
 * El usuario añade una regla a mano (ARCHITECTURE.md §13.12).
 *
 * A veces el usuario sabe algo que el sistema no puede deducir: que sus
 * facturas de un proveedor vienen siempre en français, o que un remitente
 * concreto nunca es una factura. Escribirlo es más rápido que esperar a que
 * el sistema lo aprenda por su cuenta, y el dato entra con la confianza
 * máxima: lo ha dicho el usuario.
 */
final readonly class AddMailboxKnowledge
{
    public function __construct(
        private MailboxKnowledgeRepositoryInterface $entries,
        private Clock $clock,
    ) {
    }

    public function __invoke(
        Uuid $emailAccountId,
        Uuid $organizationId,
        MailboxKnowledgeType $type,
        KnowledgeEdit $edit,
    ): MailboxKnowledgeEntry {
        $entry = new MailboxKnowledgeEntry(
            emailAccountId: $emailAccountId,
            organizationId: $organizationId,
            type: $type,
            value: $edit->value,
            providerId: $edit->providerId,
            documentType: $edit->documentType,
            billingPeriod: $edit->billingPeriod,
            source: MailboxKnowledgeSource::USER,
            confidence: $edit->confidence,
            now: $this->clock->now(),
        );

        $this->entries->save($entry);

        return $entry;
    }

    /**
     * Ignora a un remitente a petición del usuario.
     *
     * Es la versión manual de lo que hace el descarte: aquí la confianza es
     * máxima de entrada, porque el usuario lo ha dicho explícitamente y no hace
     * falta esperar al segundo descarte.
     */
    public function ignoreSender(Uuid $emailAccountId, Uuid $organizationId, string $sender): MailboxKnowledgeEntry
    {
        $entry = new MailboxKnowledgeEntry(
            emailAccountId: $emailAccountId,
            organizationId: $organizationId,
            type: MailboxKnowledgeType::IGNORED_SENDER,
            value: $sender,
            source: MailboxKnowledgeSource::USER,
            confidence: 100,
            now: $this->clock->now(),
        );

        $this->entries->save($entry);

        return $entry;
    }

    /**
     * Tipo de documento y periodicidad por defecto según el tipo de entrada.
     *
     * @return array{0: ?DocumentType, 1: ?BillingPeriod}
     */
    public static function defaultsFor(MailboxKnowledgeType $type): array
    {
        return match ($type) {
            MailboxKnowledgeType::SENDER_MAPPING, MailboxKnowledgeType::PROVIDER_HINT => [null, null],
            MailboxKnowledgeType::SUBJECT_PATTERN, MailboxKnowledgeType::DOCUMENT_PATTERN => [DocumentType::INVOICE, null],
            MailboxKnowledgeType::IGNORED_SENDER => [null, null],
        };
    }
}
