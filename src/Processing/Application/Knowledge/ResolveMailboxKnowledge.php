<?php

declare(strict_types=1);

namespace App\Processing\Application\Knowledge;

use App\Catalog\Domain\Repository\ProviderRepositoryInterface;
use App\Documents\Domain\Enum\DocumentType;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Processing\Domain\Dto\MailboxKnowledge;
use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Processing\Domain\Enum\MailboxKnowledgeType;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use App\Shared\Application\Clock;

use function mb_strtolower;
use function preg_match;

use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * Aplica el conocimiento aprendido de un buzón a un mensaje (ARCHITECTURE.md
 * §13.12).
 *
 * Es la pieza que hace que el buzon «mezca» cada vez mejor: la primera vez que
 * llega una factura de un proveedor desconocido, el sistema tiene que gastar IA
 * para entenderla; a partir de la segunda, el conocimiento del buzón ya dice
 * quién es el remitente, qué tipo de documento es y cada cuánto se cobra, y el
 * mensaje se resuelve sin pagar nada.
 *
 * **El conocimiento es del buzón, no del mundo.** Por eso la consulta va
 * siempre por `emailAccountId`: lo aprendido en el buzón de un usuario no
 * puede afectar al buzón de otro (D-33).
 *
 * Cada entrada que casa se anota como usada. Eso da dos cosas: la señal para
 * podar lo que no sirve y el refuerzo de la confianza de lo que sí.
 */
final readonly class ResolveMailboxKnowledge
{
    public function __construct(
        private MailboxKnowledgeRepositoryInterface $entries,
        private ProviderRepositoryInterface $providers,
        private Clock $clock,
    ) {
    }

    public function __invoke(Uuid $emailAccountId, ImapMessageHeader $header): MailboxKnowledge
    {
        $entries = $this->entries->findForAccount($emailAccountId);

        if ([] === $entries) {
            return MailboxKnowledge::empty();
        }

        $matched = [];
        $ignored = $this->matchIgnored($entries, $header);
        $providerEntry = $this->matchProvider($entries, $header);
        $subjectEntry = $this->matchSubject($entries, $header);
        $documentEntry = $this->matchDocument($entries, $header);

        foreach ([$ignored, $providerEntry, $subjectEntry, $documentEntry] as $entry) {
            if (null !== $entry) {
                $matched[] = $entry;
            }
        }

        $providerId = $providerEntry?->getProviderId();
        $provider = null;

        if (null !== $providerId) {
            $provider = $this->providers->find($providerId);
        }

        $knowledge = new MailboxKnowledge(
            matched: $matched,
            ignored: $ignored,
            providerEntry: $providerEntry,
            subjectEntry: $subjectEntry,
            documentEntry: $documentEntry,
            provider: $provider,
            documentType: $this->documentType($subjectEntry, $documentEntry),
            billingPeriod: $providerEntry?->getBillingPeriod(),
        );

        $this->recordHits($matched);

        return $knowledge;
    }

    /**
     * Un remitente ignorado se salta el mensaje entero: ni se descarga el
     * cuerpo ni se paga IA.
     *
     * @param list<MailboxKnowledgeEntry> $entries
     */
    private function matchIgnored(array $entries, ImapMessageHeader $header): ?MailboxKnowledgeEntry
    {
        foreach ($this->ofType($entries, MailboxKnowledgeType::IGNORED_SENDER) as $entry) {
            if ($this->matchesSenderOrDomain($entry->getValue(), $header)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * El remitente exacto manda sobre el dominio: es la señal más fiable.
     *
     * @param list<MailboxKnowledgeEntry> $entries
     */
    private function matchProvider(array $entries, ImapMessageHeader $header): ?MailboxKnowledgeEntry
    {
        $sender = $this->normalizeSender($header->fromAddress);

        if (null !== $sender) {
            foreach ($this->ofType($entries, MailboxKnowledgeType::SENDER_MAPPING) as $entry) {
                if ($entry->getValue() === $sender) {
                    return $entry;
                }
            }
        }

        $domain = $header->senderDomain();

        if (null !== $domain) {
            foreach ($this->ofType($entries, MailboxKnowledgeType::PROVIDER_HINT) as $entry) {
                if ($entry->getValue() === $domain) {
                    return $entry;
                }
            }

            foreach ($this->ofType($entries, MailboxKnowledgeType::SENDER_MAPPING) as $entry) {
                if ($entry->getValue() === $domain) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * @param list<MailboxKnowledgeEntry> $entries
     */
    private function matchSubject(array $entries, ImapMessageHeader $header): ?MailboxKnowledgeEntry
    {
        $subject = trim($header->subject);

        if ('' === $subject) {
            return null;
        }

        foreach ($this->ofType($entries, MailboxKnowledgeType::SUBJECT_PATTERN) as $entry) {
            if (1 === @preg_match($entry->getValue(), $subject)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param list<MailboxKnowledgeEntry> $entries
     */
    private function matchDocument(array $entries, ImapMessageHeader $header): ?MailboxKnowledgeEntry
    {
        foreach ($header->attachmentNames as $name) {
            foreach ($this->ofType($entries, MailboxKnowledgeType::DOCUMENT_PATTERN) as $entry) {
                if (1 === @preg_match($entry->getValue(), $name)) {
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * El tipo de documento deducido de las plantillas que han casado.
     */
    private function documentType(?MailboxKnowledgeEntry $subject, ?MailboxKnowledgeEntry $document): ?DocumentType
    {
        // La plantilla del adjunto es más específica que la del asunto: el
        // archivo es el documento, el asunto es solo el rótulo del correo.
        return $document?->getDocumentType() ?? $subject?->getDocumentType();
    }

    /**
     * @param list<MailboxKnowledgeEntry> $entries
     *
     * @return list<MailboxKnowledgeEntry>
     */
    private function ofType(array $entries, MailboxKnowledgeType $type): array
    {
        $filtered = [];

        foreach ($entries as $entry) {
            if ($entry->getType() === $type) {
                $filtered[] = $entry;
            }
        }

        return $filtered;
    }

    private function matchesSenderOrDomain(string $value, ImapMessageHeader $header): bool
    {
        $sender = $this->normalizeSender($header->fromAddress);

        if (null !== $sender && $sender === $value) {
            return true;
        }

        $domain = $header->senderDomain();

        return null !== $domain && $domain === $value;
    }

    private function normalizeSender(?string $sender): ?string
    {
        if (null === $sender) {
            return null;
        }

        $sender = mb_strtolower(trim($sender));

        return '' === $sender ? null : $sender;
    }

    /**
     * @param list<MailboxKnowledgeEntry> $matched
     */
    private function recordHits(array $matched): void
    {
        if ([] === $matched) {
            return;
        }

        $now = $this->clock->now();

        foreach ($matched as $entry) {
            $entry->recordHit($now);
        }

        $this->entries->saveAll($matched);
    }
}
