<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhooks;

use App\Webhooks\Application\Port\HostResolverInterface;
use App\Webhooks\Application\WebhookUrlGuard;
use App\Webhooks\Domain\Exception\UnsafeWebhookUrlException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La guarda anti-SSRF de las URLs de webhook (Fase 20).
 *
 * ## Por qué esta clase merece su propio test
 *
 * Todo lo demás del módulo se equivoca y se nota: la entrega no llega, el
 * panel enseña un error. Aquí, equivocarse por defecto es un agujero de
 * seguridad silencioso —Suscripta hace peticiones desde dentro de la red a
 * donde le digan— y equivocarse por exceso rompe integraciones legítimas. Los
 * dos lados se prueban con la misma insistencia.
 *
 * El resolutor es falso a propósito: un test que dependa del DNS real falla el
 * día que alguien cambia un registro, y con un mapa se prueban rangos que no
 * existen en ninguna red de verdad.
 */
final class WebhookUrlGuardTest extends TestCase
{
    /**
     * @param array<string, list<string>> $hostIps
     * @param list<string>                $allowed
     */
    private function guard(array $hostIps, array $allowed = []): WebhookUrlGuard
    {
        return new WebhookUrlGuard(
            new class($hostIps) implements HostResolverInterface {
                /**
                 * @param array<string, list<string>> $hostIps
                 */
                public function __construct(private readonly array $hostIps)
                {
                }

                public function resolve(string $host): array
                {
                    return $this->hostIps[$host] ?? [];
                }
            },
            $allowed,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedAddresses(): iterable
    {
        yield 'loopback IPv4' => ['127.0.0.1'];
        yield 'loopback IPv6' => ['::1'];
        yield 'red privada 10/8' => ['10.0.0.5'];
        yield 'red privada 172.16/12' => ['172.16.4.2'];
        yield 'red privada 192.168/16' => ['192.168.1.10'];
        yield 'metadatos de la nube' => ['169.254.169.254'];
        yield 'link-local' => ['169.254.1.1'];
        yield 'CGNAT' => ['100.64.0.1'];
        yield 'esta red' => ['0.0.0.0'];
        yield 'multicast' => ['224.0.0.1'];
        yield 'reservado' => ['240.0.0.1'];
        yield 'unique local IPv6' => ['fd00::1'];
        yield 'link-local IPv6' => ['fe80::1'];
        yield 'IPv4 mapeada en IPv6' => ['::ffff:10.0.0.5'];
        yield 'IPv4 mapeada en IPv6 (loopback)' => ['::ffff:127.0.0.1'];
        yield 'NAT64' => ['64:ff9b::7f00:1'];
    }

    #[DataProvider('blockedAddresses')]
    public function testAnAddressInsideTheInfrastructureIsBlocked(string $ip): void
    {
        self::assertTrue($this->guard([])->isBlocked($ip), $ip.' tiene que considerarse interna.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicAddresses(): iterable
    {
        yield 'IPv4 pública' => ['93.184.216.34'];
        yield 'IPv4 pública (Google)' => ['142.250.184.206'];
        yield 'IPv6 pública' => ['2606:2800:220:1:248:1893:25c8:1946'];
    }

    #[DataProvider('publicAddresses')]
    public function testAPublicAddressIsAllowed(string $ip): void
    {
        self::assertFalse($this->guard([])->isBlocked($ip), $ip.' tiene que considerarse pública.');
    }

    public function testAPublicHostIsPinnedToItsAddress(): void
    {
        $target = $this->guard(['erp.example.com' => ['93.184.216.34']])
            ->pin('https://erp.example.com/hooks/suscripta');

        self::assertNotNull($target);
        self::assertSame('erp.example.com', $target->host);
        self::assertSame('93.184.216.34', $target->ip);
    }

    /**
     * Basta con que **una** de las direcciones sea interna.
     *
     * Un host que devuelve una pública y una privada es exactamente cómo se
     * cuela el ataque si solo se mira la primera respuesta del DNS.
     */
    public function testOneInternalAddressAmongPublicOnesBlocksTheWholeHost(): void
    {
        $guard = $this->guard(['mixto.example.com' => ['93.184.216.34', '10.0.0.5']]);

        $this->expectException(UnsafeWebhookUrlException::class);

        $guard->pin('https://mixto.example.com/hooks');
    }

    public function testAnUnresolvableHostIsNotAsecurityProblem(): void
    {
        $target = $this->guard([])->pin('https://todavia-no-desplegado.example.com/hooks');

        self::assertNull(
            $target,
            'Dar de alta el receptor antes de desplegarlo es normal: no es un fallo de seguridad.',
        );
    }

    public function testAPlainHttpUrlIsRejected(): void
    {
        $this->expectException(UnsafeWebhookUrlException::class);

        $this->guard(['erp.example.com' => ['93.184.216.34']])->pin('http://erp.example.com/hooks');
    }

    public function testAUrlWithoutHostIsRejected(): void
    {
        $this->expectException(UnsafeWebhookUrlException::class);

        $this->guard([])->pin('https:///sin-host');
    }

    /**
     * La lista de excepciones es para desarrollo, y funciona por host exacto.
     */
    public function testAnAllowlistedHostMayPointToAPrivateAddress(): void
    {
        $guard = $this->guard(
            ['host.docker.internal' => ['192.168.65.254']],
            ['host.docker.internal'],
        );

        $target = $guard->pin('https://host.docker.internal:8443/hooks');

        self::assertNotNull($target);
        self::assertSame('192.168.65.254', $target->ip);
    }

    /**
     * La excepción es del host, no del rango: otro host privado sigue bloqueado.
     */
    public function testTheAllowlistDoesNotOpenTheWholePrivateRange(): void
    {
        $guard = $this->guard(
            ['otro.example.com' => ['192.168.65.254']],
            ['host.docker.internal'],
        );

        $this->expectException(UnsafeWebhookUrlException::class);

        $guard->pin('https://otro.example.com/hooks');
    }

    /**
     * Un literal IP no pasa por el DNS, pero se comprueba igual.
     */
    public function testAnIpLiteralIsCheckedWithoutResolving(): void
    {
        $guard = $this->guard([]);

        self::assertTrue($guard->isBlocked('10.0.0.5'));

        $this->expectException(UnsafeWebhookUrlException::class);

        $guard->pin('https://10.0.0.5/hooks');
    }

    /**
     * Los bordes de cada rango: dentro se bloquea, fuera no.
     */
    public function testTheRangeBoundariesAreExact(): void
    {
        $guard = $this->guard([]);

        self::assertTrue($guard->isBlocked('172.16.0.0'));
        self::assertTrue($guard->isBlocked('172.31.255.255'));
        self::assertFalse($guard->isBlocked('172.32.0.0'), '172.32/12 ya no es RFC 1918.');
        self::assertFalse($guard->isBlocked('172.15.255.255'));

        self::assertTrue($guard->isBlocked('100.64.0.0'));
        self::assertFalse($guard->isBlocked('100.128.0.0'), '100.128/10 está fuera del CGNAT.');

        self::assertTrue($guard->isBlocked('169.254.0.1'));
        self::assertFalse($guard->isBlocked('169.255.0.1'));
    }
}
