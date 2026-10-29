<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailbox\Application\Imap\Discovery;

use App\Mailbox\Application\Imap\Discovery\DiscoverImapSettings;
use App\Mailbox\Application\Imap\Discovery\ImapEndpointProbeInterface;
use App\Mailbox\Application\Imap\Discovery\SrvRecord;
use App\Mailbox\Application\Imap\Discovery\SrvResolverInterface;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Exception\ImapConnectionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * La detección del servidor es lo único que evita que el usuario tenga que
 * buscar el host en la página de ayuda de su proveedor. El orden importa: un
 * SRV publicado por el dominio manda sobre cualquier convención de nombres.
 */
#[CoversClass(DiscoverImapSettings::class)]
final class DiscoverImapSettingsTest extends TestCase
{
    public function testAPublishedSrvRecordWins(): void
    {
        $discovery = new DiscoverImapSettings(
            srv: $this->srv(['_imaps._tcp.ejemplo.com' => new SrvRecord('correo.ejemplo.com.', 1993)]),
            probe: $this->probeThatNeverResponds(),
        );

        $endpoint = $discovery('Facturas@Ejemplo.com');

        self::assertSame('correo.ejemplo.com', $endpoint->host);
        self::assertSame(1993, $endpoint->port);
        self::assertSame(ImapEncryption::SSL, $endpoint->encryption);
        self::assertSame('srv', $endpoint->source);
    }

    public function testThePlainImapRecordFallsBackToStartTls(): void
    {
        // `_imap._tcp` no implica TLS: el RFC 6186 dice que hay que negociarlo.
        $discovery = new DiscoverImapSettings(
            srv: $this->srv(['_imap._tcp.ejemplo.com' => new SrvRecord('correo.ejemplo.com', 143)]),
            probe: $this->probeThatNeverResponds(),
        );

        $endpoint = $discovery('facturas@ejemplo.com');

        self::assertSame(143, $endpoint->port);
        self::assertSame(ImapEncryption::STARTTLS, $endpoint->encryption);
    }

    public function testWithoutSrvTheFirstHostThatActuallyAnswersIsProposed(): void
    {
        // `imap.ejemplo.com` no existe pero `mail.ejemplo.com` sí: proponer el
        // primero sin comprobarlo llenaría el formulario de un servidor muerto.
        $discovery = new DiscoverImapSettings(
            srv: $this->srv([]),
            probe: $this->probeAnsweringOnly('mail.ejemplo.com', 993),
        );

        $endpoint = $discovery('facturas@ejemplo.com');

        self::assertSame('mail.ejemplo.com', $endpoint->host);
        self::assertSame(993, $endpoint->port);
        self::assertSame(ImapEncryption::SSL, $endpoint->encryption);
        self::assertSame('convención', $endpoint->source);
    }

    public function testAStartTlsOnlyServerIsFoundToo(): void
    {
        $discovery = new DiscoverImapSettings(
            srv: $this->srv([]),
            probe: $this->probeAnsweringOnly('imap.ejemplo.com', 143),
        );

        $endpoint = $discovery('facturas@ejemplo.com');

        self::assertSame(143, $endpoint->port);
        self::assertSame(ImapEncryption::STARTTLS, $endpoint->encryption);
    }

    public function testNothingRespondingIsBetterThanAWrongGuess(): void
    {
        $discovery = new DiscoverImapSettings(
            srv: $this->srv([]),
            probe: $this->probeThatNeverResponds(),
        );

        $this->expectException(ImapConnectionException::class);
        $this->expectExceptionMessageMatches('/escríbelo a mano/');

        $discovery('facturas@ejemplo.com');
    }

    public function testAnAddressWithoutDomainIsRejectedBeforeTouchingTheNetwork(): void
    {
        $srv = $this->createMock(SrvResolverInterface::class);
        $srv->expects(self::never())->method('resolve');

        $discovery = new DiscoverImapSettings(srv: $srv, probe: $this->probeThatNeverResponds());

        $this->expectException(ImapConnectionException::class);
        $this->expectExceptionMessageMatches('/Escribe primero la dirección/');

        $discovery('facturas');
    }

    /**
     * @param array<string, SrvRecord> $records
     */
    private function srv(array $records): SrvResolverInterface
    {
        return new class($records) implements SrvResolverInterface {
            /**
             * @param array<string, SrvRecord> $records
             */
            public function __construct(private readonly array $records)
            {
            }

            public function resolve(string $name): ?SrvRecord
            {
                return $this->records[$name] ?? null;
            }
        };
    }

    private function probeThatNeverResponds(): ImapEndpointProbeInterface
    {
        return new class implements ImapEndpointProbeInterface {
            public function responds(string $host, int $port, ImapEncryption $encryption): bool
            {
                return false;
            }
        };
    }

    private function probeAnsweringOnly(string $host, int $port): ImapEndpointProbeInterface
    {
        return new class($host, $port) implements ImapEndpointProbeInterface {
            public function __construct(
                private readonly string $host,
                private readonly int $port,
            ) {
            }

            public function responds(string $host, int $port, ImapEncryption $encryption): bool
            {
                return $this->host === $host && $this->port === $port;
            }
        };
    }
}
