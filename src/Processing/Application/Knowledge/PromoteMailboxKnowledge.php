<?php

declare(strict_types=1);

namespace App\Processing\Application\Knowledge;

use App\Catalog\Domain\Entity\ProviderIdentity;
use App\Catalog\Domain\Enum\ProviderIdentitySource;
use App\Catalog\Domain\Enum\ProviderIdentityType;
use App\Catalog\Domain\Repository\ProviderIdentityRepositoryInterface;
use App\Catalog\Domain\Repository\ProviderRepositoryInterface;
use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Processing\Domain\Enum\MailboxKnowledgeType;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Domain\Exception\InvalidArgumentException;

use function str_contains;

/**
 * Promueve a conocimiento global lo aprendido en un buzón (D-33).
 *
 * **La promoción es siempre explícita.** El sistema no sube al catálogo global
 * lo que ha aprendido de un buzón por su cuenta: un patrón equivocado en el
 * buzón de un usuario se convertiría en un error para todos. El usuario
 * revisa lo aprendido, decide que es cierto y lo promueve.
 *
 * Solo se puede promover hacia un proveedor del catálogo global. Si el
 * proveedor es del tenant, promoverlo filtraría datos de un usuario al
 * resto, y para eso el catálogo global ya tiene su propia vía.
 */
final readonly class PromoteMailboxKnowledge
{
    public function __construct(
        private MailboxKnowledgeRepositoryInterface $entries,
        private ProviderIdentityRepositoryInterface $identities,
        private ProviderRepositoryInterface $providers,
        private Clock $clock,
    ) {
    }

    /**
     * @return ProviderIdentity la identidad global creada o ya existente
     */
    public function __invoke(MailboxKnowledgeEntry $entry): ProviderIdentity
    {
        if (!$entry->getType()->carriesProvider()) {
            throw new InvalidArgumentException('Solo se puede promover el conocimiento que apunta a un proveedor.');
        }

        $providerId = $entry->getProviderId();

        if (null === $providerId) {
            throw new InvalidArgumentException('Esta entrada todavía no sabe a qué proveedor pertenece.');
        }

        $provider = $this->providers->find($providerId);

        if (null === $provider) {
            throw new InvalidArgumentException('El proveedor de esta entrada ya no existe.');
        }

        if (null !== $provider->getOrganizationId()) {
            throw new InvalidArgumentException('Solo se puede promover un proveedor del catálogo global.');
        }

        $type = $this->identityType($entry);
        $value = $entry->getValue();
        $existing = $this->identities->findByTypeAndValue($type, $value);

        if (null !== $existing) {
            $entry->markPromoted($this->clock->now());
            $this->entries->save($entry);

            return $existing;
        }

        $identity = new ProviderIdentity(
            provider: $provider,
            type: $type,
            value: $value,
            source: ProviderIdentitySource::LEARNED,
            confidence: $entry->getConfidence(),
        );

        $this->identities->save($identity);

        $entry->markPromoted($this->clock->now());
        $this->entries->save($entry);

        return $identity;
    }

    /**
     * Una dirección de correo se promueve como remitente y un dominio como
     * dominio. La distinción importa: una identidad de dominio vale para
     * cualquier dirección de ese dominio.
     */
    private function identityType(MailboxKnowledgeEntry $entry): ProviderIdentityType
    {
        if (MailboxKnowledgeType::PROVIDER_HINT === $entry->getType()) {
            return ProviderIdentityType::DOMAIN;
        }

        return str_contains($entry->getValue(), '@')
            ? ProviderIdentityType::SENDER
            : ProviderIdentityType::DOMAIN;
    }
}
