<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Imap\Discovery;

use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Exception\ImapConnectionException;

use function count;
use function explode;
use function mb_strtolower;
use function sprintf;
use function trim;

/**
 * Deduce el servidor IMAP a partir de la dirección de correo.
 *
 * ## Por qué no se le pregunta al usuario
 *
 * «Servidor IMAP» es el campo donde más gente se atasca: la respuesta está en la
 * página de ayuda del proveedor, que cambia de nombre cada dos años. La
 * aplicación ya tiene un catálogo para Gmail, Microsoft, Yahoo e iCloud; esto
 * cubre el resto —hostings, buzones corporativos, proveedores pequeños— sin
 * pedirle a nadie que busque nada.
 *
 * ## Estrategia, de más fiable a menos
 *
 * 1. **SRV de RFC 6186**: `_imaps._tcp.<dominio>` y, si no hay, `_imap._tcp`.
 *    Es un dato publicado a propósito para esto.
 * 2. **Convención**: `imap.<dominio>`, `mail.<dominio>` y el propio dominio, en
 *    993/TLS y en 143/STARTTLS, **comprobando que responden**. Sin la
 *    comprobación esto sería adivinar y llenar el formulario de servidores que
 *    no existen.
 *
 * ## Lo que esto no hace
 *
 * No descarga el XML de autoconfiguración de Thunderbird. Sería un tercer
 * método, pero implica pedirle un fichero a un servidor que el usuario no
 * controla: eso es superficie de SSRF a cambio de un caso que la convención ya
 * cubre casi siempre. El resultado es una sugerencia editable, así que el
 * usuario que sepa su servidor lo escribe y no pierde nada.
 */
final readonly class DiscoverImapSettings
{
    /**
     * Nombres que usan casi todos los proveedores para el mismo servidor.
     */
    private const array HOST_PATTERNS = ['imap.%s', 'mail.%s', '%s'];

    public function __construct(
        private SrvResolverInterface $srv,
        private ImapEndpointProbeInterface $probe,
    ) {
    }

    /**
     * @throws ImapConnectionException si no se ha podido deducir nada
     */
    public function __invoke(string $emailAddress): DiscoveredImapEndpoint
    {
        $domain = $this->domainOf($emailAddress);

        $fromSrv = $this->fromSrv($domain);

        if (null !== $fromSrv) {
            return $fromSrv;
        }

        return $this->fromConvention($domain);
    }

    /**
     * El dominio es lo único que hace falta: la parte local no dice nada del
     * servidor, y hay proveedores que la usan como comodín.
     */
    private function domainOf(string $emailAddress): string
    {
        $parts = explode('@', trim($emailAddress));

        if (2 !== count($parts) || '' === $parts[1]) {
            throw new ImapConnectionException('Escribe primero la dirección del buzón para poder detectar el servidor.');
        }

        return mb_strtolower(trim($parts[1]));
    }

    private function fromSrv(string $domain): ?DiscoveredImapEndpoint
    {
        foreach ([['_imaps._tcp', ImapEncryption::SSL], ['_imap._tcp', ImapEncryption::STARTTLS]] as [$prefix, $encryption]) {
            $record = $this->srv->resolve(sprintf('%s.%s', $prefix, $domain));

            if (null !== $record) {
                return new DiscoveredImapEndpoint($record->target, $record->port, $encryption, 'srv');
            }
        }

        return null;
    }

    private function fromConvention(string $domain): DiscoveredImapEndpoint
    {
        foreach (self::HOST_PATTERNS as $pattern) {
            $host = sprintf($pattern, $domain);

            foreach ([ImapEncryption::SSL, ImapEncryption::STARTTLS] as $encryption) {
                $port = $encryption->defaultPort();

                if ($this->probe->responds($host, $port, $encryption)) {
                    return new DiscoveredImapEndpoint($host, $port, $encryption, 'convención');
                }
            }
        }

        throw new ImapConnectionException('No hemos podido detectar el servidor de este dominio. Búscalo en la ayuda de tu proveedor y escríbelo a mano.');
    }
}
