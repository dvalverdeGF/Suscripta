<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailbox\Domain\Entity;

use App\Mailbox\Domain\Entity\EmailAccount;
use App\Shared\Domain\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * La lista de remitentes autorizados es lo único que separa la dirección de
 * ingesta de un buzón abierto donde cualquiera podría meter facturas falsas
 * (SECURITY.md §2.4).
 */
#[CoversClass(EmailAccount::class)]
final class EmailAccountForwardingTest extends TestCase
{
    private function account(): EmailAccount
    {
        return new EmailAccount(Uuid::v7(), 'yo@ovh.com');
    }

    public function testForwardingIsOffByDefault(): void
    {
        $account = $this->account();

        self::assertFalse($account->isForwardingEnabled());
        self::assertNull($account->getForwardingAddress());
        self::assertSame([], $account->getForwardingSenders());
    }

    public function testEnablingForwardingStoresTheAddressAndTheSenders(): void
    {
        $account = $this->account();
        $account->enableForwarding('inbox-abc@inbound.suscripta.app', ['yo@ovh.com']);

        self::assertTrue($account->isForwardingEnabled());
        self::assertSame('inbox-abc@inbound.suscripta.app', $account->getForwardingAddress());
        self::assertSame(['yo@ovh.com'], $account->getForwardingSenders());
    }

    public function testSendersAreNormalisedAndDeduplicated(): void
    {
        $account = $this->account();
        $account->enableForwarding('inbox-abc@inbound.suscripta.app', [
            '  Yo@OVH.com ',
            'yo@ovh.com',
            '@Facturacion.com',
            '',
        ]);

        self::assertSame(['yo@ovh.com', '@facturacion.com'], $account->getForwardingSenders());
    }

    public function testAnInvalidSenderIsRejected(): void
    {
        $account = $this->account();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no es una dirección ni un dominio válido');

        $account->enableForwarding('inbox-abc@inbound.suscripta.app', ['esto no es un correo']);
    }

    public function testAnAuthorizedAddressIsAccepted(): void
    {
        $account = $this->account();
        $account->enableForwarding('inbox-abc@inbound.suscripta.app', ['yo@ovh.com']);

        self::assertTrue($account->isSenderAuthorized('yo@ovh.com'));
        self::assertTrue($account->isSenderAuthorized('YO@OVH.COM'));
        self::assertTrue($account->isSenderAuthorized('  yo@ovh.com  '));
    }

    public function testAnAuthorizedDomainAcceptsAnyAddressOnIt(): void
    {
        $account = $this->account();
        $account->enableForwarding('inbox-abc@inbound.suscripta.app', ['@ovh.com']);

        self::assertTrue($account->isSenderAuthorized('facturacion@ovh.com'));
        self::assertTrue($account->isSenderAuthorized('noreply@ovh.com'));
    }

    /**
     * Un dominio autorizado no debe aceptar un dominio que simplemente
     * *termine* igual: `@ovh.com` no autoriza a `atacante@falso-ovh.com`.
     */
    public function testAnAuthorizedDomainDoesNotMatchALookalikeDomain(): void
    {
        $account = $this->account();
        $account->enableForwarding('inbox-abc@inbound.suscripta.app', ['@ovh.com']);

        self::assertFalse($account->isSenderAuthorized('atacante@falso-ovh.com'));
        self::assertFalse($account->isSenderAuthorized('atacante@ovh.com.evil.io'));
    }

    public function testAnUnknownSenderIsRejected(): void
    {
        $account = $this->account();
        $account->enableForwarding('inbox-abc@inbound.suscripta.app', ['yo@ovh.com']);

        self::assertFalse($account->isSenderAuthorized('atacante@ejemplo.com'));
        self::assertFalse($account->isSenderAuthorized(''));
    }

    public function testWithNoSendersNobodyIsAuthorized(): void
    {
        $account = $this->account();
        $account->enableForwarding('inbox-abc@inbound.suscripta.app', []);

        self::assertFalse($account->isSenderAuthorized('yo@ovh.com'));
    }

    public function testRotatingTheAddressKeepsTheAuthorizedSenders(): void
    {
        $account = $this->account();
        $account->enableForwarding('inbox-abc@inbound.suscripta.app', ['yo@ovh.com']);
        $account->rotateForwardingAddress('inbox-def@inbound.suscripta.app');

        self::assertSame('inbox-def@inbound.suscripta.app', $account->getForwardingAddress());
        self::assertSame(['yo@ovh.com'], $account->getForwardingSenders());
        self::assertTrue($account->isForwardingEnabled());
    }

    public function testRotatingWithoutForwardingIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->account()->rotateForwardingAddress('inbox-def@inbound.suscripta.app');
    }

    public function testDisablingKeepsTheAddressButStopsAcceptingMail(): void
    {
        $account = $this->account();
        $account->enableForwarding('inbox-abc@inbound.suscripta.app', ['yo@ovh.com']);
        $account->disableForwarding();

        self::assertFalse($account->isForwardingEnabled());
        self::assertSame('inbox-abc@inbound.suscripta.app', $account->getForwardingAddress());
    }

    public function testTheSendersCanBeReplaced(): void
    {
        $account = $this->account();
        $account->enableForwarding('inbox-abc@inbound.suscripta.app', ['yo@ovh.com']);
        $account->setForwardingSenders(['@ovh.com', 'gestoria@ejemplo.com']);

        self::assertSame(['@ovh.com', 'gestoria@ejemplo.com'], $account->getForwardingSenders());
        self::assertFalse($account->isSenderAuthorized('otro@ejemplo.com'));
        self::assertTrue($account->isSenderAuthorized('facturacion@ovh.com'));
        self::assertTrue($account->isSenderAuthorized('gestoria@ejemplo.com'));
    }
}
