<?php

declare(strict_types=1);

namespace App\Processing\Application\Provider;

use App\Catalog\Domain\Entity\ProviderIdentity;
use App\Catalog\Domain\Enum\ProviderIdentityType;
use App\Catalog\Domain\Repository\ProviderIdentityRepositoryInterface;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Processing\Domain\Provider\ProviderMatch;
use App\Processing\Domain\Provider\ProviderResolverInterface;
use App\Shared\Application\Clock;

use function mb_strtolower;
use function mb_substr;
use function preg_match;
use function trim;

/**
 * Escalera de resolución de proveedores (ARCHITECTURE.md §13.7).
 *
 * Se prueba de lo más específico a lo más genérico, y se para en el primer
 * acierto:
 *
 * 1. **Dirección de envío** (`facturas@ovh.com`): la más fiable, porque un
 *    proveedor puede facturar desde varios dominios pero rara vez comparte
 *    dirección con otro.
 * 2. **Dominio** (`ovh.com`): cubre el caso normal y los subdominios de envío.
 * 3. **Patrón de asunto**: para proveedores que facturan desde un dominio
 *    compartido (pasarelas de pago, marketplaces).
 * 4. **Patrón de adjunto**: último recurso cuando el asunto es genérico.
 *
 * Cada acierto refuerza la identidad (`recordHit`), que es la señal que
 * permitirá podar el catálogo con el tiempo: una identidad que nunca acierta
 * sobra.
 *
 * Si nada acierta, se devuelve un nombre **provisional** en lugar de un
 * desconocido absoluto. Sin eso, la primera factura de cualquier proveedor
 * nuevo acabaría en revisión para siempre y el producto no descubriría nada.
 */
final readonly class ProviderResolver implements ProviderResolverInterface
{
    public function __construct(
        private ProviderIdentityRepositoryInterface $identities,
        private Clock $clock,
    ) {
    }

    public function resolve(ImapMessageHeader $header): ProviderMatch
    {
        $match = $this->matchSender($header)
            ?? $this->matchDomain($header)
            ?? $this->matchSubject($header)
            ?? $this->matchAttachment($header);

        if (null !== $match) {
            return $match;
        }

        $provisional = $this->provisionalName($header);

        return null === $provisional ? ProviderMatch::unknown() : ProviderMatch::provisional($provisional);
    }

    private function matchSender(ImapMessageHeader $header): ?ProviderMatch
    {
        if (null === $header->fromAddress) {
            return null;
        }

        $identity = $this->identities->findByTypeAndValue(
            ProviderIdentityType::SENDER,
            mb_strtolower(trim($header->fromAddress)),
        );

        return null === $identity ? null : $this->accept($identity, 'sender');
    }

    private function matchDomain(ImapMessageHeader $header): ?ProviderMatch
    {
        $domain = $header->senderDomain();

        if (null === $domain) {
            return null;
        }

        $identity = $this->identities->findByTypeAndValue(ProviderIdentityType::DOMAIN, $domain);

        return null === $identity ? null : $this->accept($identity, 'domain');
    }

    private function matchSubject(ImapMessageHeader $header): ?ProviderMatch
    {
        return $this->matchPattern(ProviderIdentityType::SUBJECT_PATTERN, $header->subject, 'subject_pattern');
    }

    private function matchAttachment(ImapMessageHeader $header): ?ProviderMatch
    {
        if ([] === $header->attachmentNames) {
            return null;
        }

        // Los patrones se leen una sola vez: un mensaje con cinco adjuntos no
        // debe provocar cinco consultas idénticas.
        $identities = $this->identities->findByType(ProviderIdentityType::ATTACHMENT_PATTERN);

        if ([] === $identities) {
            return null;
        }

        foreach ($header->attachmentNames as $name) {
            $match = $this->matchPatternIn($identities, $name, 'attachment_pattern');

            if (null !== $match) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Los patrones se guardan como expresiones regulares en `value`.
     *
     * Se validan con `@preg_match` para que un patrón mal escrito en el catálogo
     * no emita un aviso en cada mensaje: simplemente no coincide.
     */
    private function matchPattern(ProviderIdentityType $type, ?string $subject, string $matchedBy): ?ProviderMatch
    {
        if (null === $subject || '' === trim($subject)) {
            return null;
        }

        return $this->matchPatternIn($this->identities->findByType($type), $subject, $matchedBy);
    }

    /**
     * @param list<ProviderIdentity> $identities
     */
    private function matchPatternIn(array $identities, ?string $subject, string $matchedBy): ?ProviderMatch
    {
        if (null === $subject || '' === trim($subject)) {
            return null;
        }

        foreach ($identities as $identity) {
            $pattern = $identity->getValue();

            if (1 === @preg_match($pattern, $subject)) {
                return $this->accept($identity, $matchedBy);
            }
        }

        return null;
    }

    private function accept(ProviderIdentity $identity, string $matchedBy): ProviderMatch
    {
        $identity->recordHit($this->clock->now());
        $this->identities->save($identity, false);

        return new ProviderMatch(
            provider: $identity->getProvider(),
            matchedBy: $matchedBy,
            confidence: $identity->getConfidence(),
        );
    }

    /**
     * Nombre provisional: el que se ve en el cliente de correo y, si no hay, el
     * dominio. Es exactamente lo que haría una persona al mirar el mensaje.
     */
    private function provisionalName(ImapMessageHeader $header): ?string
    {
        $fromName = null === $header->fromName ? null : trim($header->fromName);

        if (null !== $fromName && '' !== $fromName) {
            return mb_substr($fromName, 0, 120);
        }

        $domain = $header->senderDomain();

        return null === $domain || '' === $domain ? null : mb_substr($domain, 0, 120);
    }
}
