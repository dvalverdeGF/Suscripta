<?php

declare(strict_types=1);

namespace App\Webhooks\Application;

use App\Webhooks\Application\Port\HostResolverInterface;
use App\Webhooks\Domain\Exception\UnsafeWebhookUrlException;

use function count;
use function explode;

use const FILTER_VALIDATE_IP;

use function filter_var;
use function in_array;
use function inet_pton;
use function intdiv;
use function is_string;
use function ord;
use function parse_url;

use const PHP_URL_HOST;
use const PHP_URL_SCHEME;

use function sprintf;
use function str_contains;
use function str_starts_with;
use function strcmp;
use function strlen;
use function strtolower;
use function substr;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function trim;

/**
 * Guarda anti-SSRF de las URLs de webhook (Fase 20).
 *
 * ## El problema, en una frase
 *
 * Suscripta hace un `POST` a la URL que le diga un administrador de la
 * organización. Si esa URL apunta a `https://10.0.0.5/`, la petición sale
 * **desde dentro de la red del servidor** y atraviesa el cortafuegos por el
 * lado de dentro. Y si apunta a `http://169.254.169.254/`, golpea el servicio
 * de metadatos de la nube, que es donde están las credenciales del servidor.
 * No hace falta ser nadie para probarlo: basta con ser administrador de una
 * organización, o con robar una cuenta que lo sea.
 *
 * ## Qué se comprueba
 *
 * 1. El esquema es `https`. Un webhook en claro manda el cuerpo firmado —y con
 *    él los datos de facturación del cliente— a cualquiera que escuche.
 * 2. **Todas** las direcciones a las que resuelve el host son públicas. No solo
 *    la primera: un host puede devolver una pública y una privada, y quedarse
 *    con la primera es cómo se cuela el ataque.
 * 3. La dirección concreta no está en ningún rango interno: loopback, redes
 *    privadas, link-local (los metadatos), CGNAT, rangos reservados ni
 *    multicast. Se comprueban también las direcciones IPv6, incluidas las
 *    IPv4-mapeadas (`::ffff:10.0.0.1`), que son la forma más habitual de
 *    esquivar una comprobación que solo mira IPv4.
 *
 * ## Por qué se «fija» la dirección
 *
 * Validar y luego conectar sin más deja una ventana abierta: el DNS puede
 * devolver una dirección pública en la validación y una privada un segundo
 * después (DNS rebinding). Por eso `pin()` devuelve la dirección validada y
 * `DeliverWebhook` se la pasa al cliente HTTP en la opción `resolve`, que
 * conecta a esa IP concreta y no vuelve a preguntar. Además la entrega va con
 * `max_redirects: 0`: una redirección `302` desde un host público a
 * `http://169.254.169.254/` sería el mismo ataque con un salto intermedio.
 *
 * ## Lo que no se comprueba, y por qué no rompe nada
 *
 * Si el host **no resuelve**, no se rechaza. Es el caso normal de quien da de
 * alta el receptor antes de desplegarlo, y en la entrega real se comporta como
 * cualquier fallo de red: se reintenta. La guarda no es un validador de
 * disponibilidad, es un cortafuegos.
 */
final readonly class WebhookUrlGuard
{
    /**
     * Rangos que nunca son un servicio público al que avisar.
     *
     * @var list<string>
     */
    private const array BLOCKED_IPV4 = [
        '0.0.0.0/8',        // «esta red»
        '10.0.0.0/8',       // RFC 1918
        '100.64.0.0/10',    // CGNAT
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local: metadatos de las nubes
        '172.16.0.0/12',    // RFC 1918
        '192.0.0.0/24',     // protocolos especiales
        '192.0.2.0/24',     // documentación
        '192.168.0.0/16',   // RFC 1918
        '198.18.0.0/15',    // pruebas de rendimiento
        '198.51.100.0/24',  // documentación
        '203.0.113.0/24',   // documentación
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reservado
    ];

    /**
     * @var list<string>
     */
    private const array BLOCKED_IPV6 = [
        '::/128',           // sin dirección
        '::1/128',          // loopback
        '64:ff9b::/96',     // NAT64: esconde una IPv4 dentro de una IPv6
        '100::/64',         // descarte
        '2001:db8::/32',    // documentación
        'fc00::/7',         // unique local (el «192.168.x» de IPv6)
        'fe80::/10',        // link-local
        'ff00::/8',         // multicast
    ];

    /**
     * @param list<string> $allowedPrivateHosts
     */
    public function __construct(
        private HostResolverInterface $resolver,
        #[Autowire('%env(csv:WEBHOOK_ALLOWED_PRIVATE_HOSTS)%')]
        private array $allowedPrivateHosts = [],
    ) {
    }

    /**
     * Valida la URL y devuelve la dirección a la que hay que conectar.
     *
     * Devuelve `null` cuando el host no resuelve: no es un problema de
     * seguridad, y quien llama decide qué hacer (la entrega lo trata como un
     * fallo de red normal).
     *
     * @throws UnsafeWebhookUrlException si el destino es interno
     */
    public function pin(string $url): ?WebhookTarget
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($scheme) || 'https' !== strtolower($scheme)) {
            throw UnsafeWebhookUrlException::unsupportedScheme($url);
        }

        if (!is_string($host) || '' === $host) {
            throw UnsafeWebhookUrlException::missingHost($url);
        }

        // `parse_url` devuelve las IPv6 con corchetes: `[::1]`.
        $host = trim($host, '[]');
        $normalizedHost = strtolower($host);

        $allowPrivate = in_array($normalizedHost, $this->allowedPrivateHosts, true);

        // Una dirección literal no pasa por el DNS. Se comprueba aquí y no en
        // el resolutor: si dependiera de que el resolutor se acuerde de
        // devolverla, una implementación nueva podría abrir el agujero sin que
        // nadie lo notara.
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            if (!$allowPrivate && $this->isBlocked($host)) {
                throw UnsafeWebhookUrlException::privateTarget($host, $host);
            }

            return new WebhookTarget($host, $host);
        }

        $addresses = $this->resolver->resolve($host);

        if (0 === count($addresses)) {
            return null;
        }

        foreach ($addresses as $address) {
            if (!$allowPrivate && $this->isBlocked($address)) {
                throw UnsafeWebhookUrlException::privateTarget($host, $address);
            }
        }

        return new WebhookTarget($host, $addresses[0]);
    }

    /**
     * ¿La dirección cae en un rango que no es un servicio público?
     */
    public function isBlocked(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if (false === $packed) {
            // No es una dirección válida: no se puede garantizar que sea
            // pública, así que se trata como interna.
            return true;
        }

        if (16 === strlen($packed)) {
            // IPv4 mapeada en IPv6 (`::ffff:10.0.0.1`): se comprueba la IPv4
            // que lleva dentro, porque es la que se acaba usando.
            if (str_starts_with($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff")) {
                return $this->matchesAny(substr($packed, 12), self::BLOCKED_IPV4);
            }

            return $this->matchesAny($packed, self::BLOCKED_IPV6);
        }

        return $this->matchesAny($packed, self::BLOCKED_IPV4);
    }

    /**
     * @param list<string> $ranges
     */
    private function matchesAny(string $packed, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($this->inRange($packed, $range)) {
                return true;
            }
        }

        return false;
    }

    private function inRange(string $packed, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }

        [$network, $bits] = explode('/', $cidr, 2);

        $networkPacked = @inet_pton($network);

        if (false === $networkPacked || strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $prefix = (int) $bits;
        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if (0 !== strcmp(substr($packed, 0, $wholeBytes), substr($networkPacked, 0, $wholeBytes))) {
            return false;
        }

        if (0 === $remainingBits) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($packed[$wholeBytes]) & $mask) === (ord($networkPacked[$wholeBytes]) & $mask);
    }

    /**
     * Solo para mensajes de error legibles desde fuera.
     */
    public function describe(WebhookTarget $target): string
    {
        return sprintf('%s (%s)', $target->host, $target->ip);
    }
}
