<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailbox\Application\Forwarding;

use App\Mailbox\Application\Forwarding\ForwardingAddressFactory;

use function explode;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function str_contains;
use function strlen;

/**
 * La dirección de ingesta es un secreto: quien la conozca puede intentar
 * inyectar correo. Por eso el token es aleatorio y no derivado de nada.
 */
#[CoversClass(ForwardingAddressFactory::class)]
final class ForwardingAddressFactoryTest extends TestCase
{
    public function testItBuildsAnAddressOnTheConfiguredDomain(): void
    {
        $address = new ForwardingAddressFactory('inbound.suscripta.app')->generate();

        self::assertStringEndsWith('@inbound.suscripta.app', $address);
        self::assertStringStartsWith('inbox-', $address);
    }

    public function testTheTokenIsLongEnoughToNotBeGuessable(): void
    {
        $address = new ForwardingAddressFactory('inbound.suscripta.app')->generate();
        $local = explode('@', $address)[0];
        $token = explode('-', $local, 2)[1];

        // 16 bytes en hexadecimal: 32 caracteres, 128 bits de entropía.
        self::assertSame(32, strlen($token));
        self::assertSame(1, preg_match('/^[0-9a-f]{32}$/', $token));
    }

    public function testTwoAddressesAreNeverTheSame(): void
    {
        $factory = new ForwardingAddressFactory('inbound.suscripta.app');

        $addresses = [];

        for ($i = 0; $i < 50; ++$i) {
            $addresses[] = $factory->generate();
        }

        self::assertCount(50, $addresses);
        self::assertCount(50, array_unique($addresses));
    }

    public function testTheDomainIsNormalised(): void
    {
        $address = new ForwardingAddressFactory('  Inbound.Suscripta.APP  ')->generate();

        self::assertStringEndsWith('@inbound.suscripta.app', $address);
    }

    public function testThePrefixCanBeChanged(): void
    {
        $address = new ForwardingAddressFactory('inbound.suscripta.app', 'facturas')->generate();

        self::assertStringStartsWith('facturas-', $address);
    }

    public function testTheAddressNeverLeaksTheAccountOrTheOrganization(): void
    {
        $address = new ForwardingAddressFactory('inbound.suscripta.app')->generate();

        self::assertFalse(str_contains($address, 'ovh'));
        self::assertFalse(str_contains($address, 'acme'));
    }
}
