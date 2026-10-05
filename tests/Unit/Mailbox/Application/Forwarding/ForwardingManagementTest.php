<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailbox\Application\Forwarding;

use App\Mailbox\Application\Forwarding\DisableEmailForwarding;
use App\Mailbox\Application\Forwarding\EnableEmailForwarding;
use App\Mailbox\Application\Forwarding\ForwardingAddressFactory;
use App\Mailbox\Application\Forwarding\RotateForwardingAddress;
use App\Mailbox\Application\Forwarding\UpdateForwardingSenders;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Tests\Support\Mailbox\InMemoryEmailAccountRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Gestión de la dirección de ingesta por reenvío (D-21).
 */
#[CoversClass(EnableEmailForwarding::class)]
#[CoversClass(RotateForwardingAddress::class)]
#[CoversClass(DisableEmailForwarding::class)]
#[CoversClass(UpdateForwardingSenders::class)]
final class ForwardingManagementTest extends TestCase
{
    private InMemoryEmailAccountRepository $accounts;
    private AuditLoggerInterface&MockObject $auditLogger;

    /** @var list<array{action: AuditAction, metadata: array<string, scalar|null>}> */
    private array $audited = [];

    private EmailAccount $account;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryEmailAccountRepository();
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);

        $this->audited = [];
        $this->auditLogger
            ->method('log')
            ->willReturnCallback(function (AuditAction $action, ?string $targetType = null, ?string $targetId = null, array $metadata = []): void {
                $this->audited[] = ['action' => $action, 'metadata' => $metadata];
            });

        $this->account = new EmailAccount(Uuid::v7(), 'yo@ovh.com');
        $this->accounts->save($this->account);
        $this->accounts->flushCount = 0;
    }

    private function enable(): EnableEmailForwarding
    {
        return new EnableEmailForwarding(
            accounts: $this->accounts,
            addresses: new ForwardingAddressFactory('inbound.suscripta.app'),
            tenantContext: new TenantContext(),
            auditLogger: $this->auditLogger,
        );
    }

    private function rotate(): RotateForwardingAddress
    {
        return new RotateForwardingAddress(
            accounts: $this->accounts,
            addresses: new ForwardingAddressFactory('inbound.suscripta.app'),
            tenantContext: new TenantContext(),
            auditLogger: $this->auditLogger,
        );
    }

    private function disable(): DisableEmailForwarding
    {
        return new DisableEmailForwarding(
            accounts: $this->accounts,
            tenantContext: new TenantContext(),
            auditLogger: $this->auditLogger,
        );
    }

    private function updateSenders(): UpdateForwardingSenders
    {
        return new UpdateForwardingSenders(
            accounts: $this->accounts,
            tenantContext: new TenantContext(),
            auditLogger: $this->auditLogger,
        );
    }

    public function testEnablingGeneratesAnAddressAndStoresTheSenders(): void
    {
        $account = ($this->enable())($this->account, ['yo@ovh.com']);

        self::assertTrue($account->isForwardingEnabled());
        self::assertNotNull($account->getForwardingAddress());
        self::assertStringEndsWith('@inbound.suscripta.app', (string) $account->getForwardingAddress());
        self::assertSame(['yo@ovh.com'], $account->getForwardingSenders());
        self::assertSame(1, $this->accounts->flushCount);
    }

    public function testEnablingIsAudited(): void
    {
        ($this->enable())($this->account, ['yo@ovh.com']);

        self::assertCount(1, $this->audited);
        self::assertSame(AuditAction::EMAIL_FORWARDING_ENABLED, $this->audited[0]['action']);
        self::assertFalse($this->audited[0]['metadata']['rotated']);
    }

    /**
     * Volver a activar el reenvío no debe cambiar la dirección: el usuario ya
     * la habrá dado de alta en su proveedor de correo.
     */
    public function testEnablingTwiceKeepsTheSameAddressAndOnlyUpdatesTheSenders(): void
    {
        $account = ($this->enable())($this->account, ['yo@ovh.com']);
        $address = $account->getForwardingAddress();

        $account = ($this->enable())($account, ['yo@ovh.com', '@gestoria.com']);

        self::assertSame($address, $account->getForwardingAddress());
        self::assertSame(['yo@ovh.com', '@gestoria.com'], $account->getForwardingSenders());
        self::assertTrue($this->audited[1]['metadata']['rotated']);
    }

    public function testRotatingChangesTheAddressAndKeepsTheSenders(): void
    {
        $account = ($this->enable())($this->account, ['yo@ovh.com']);
        $previous = $account->getForwardingAddress();

        $account = ($this->rotate())($account);

        self::assertNotSame($previous, $account->getForwardingAddress());
        self::assertSame(['yo@ovh.com'], $account->getForwardingSenders());
        self::assertTrue($account->isForwardingEnabled());
    }

    public function testRotatingIsAuditedWithThePreviousAddress(): void
    {
        $account = ($this->enable())($this->account, ['yo@ovh.com']);
        $previous = $account->getForwardingAddress();

        ($this->rotate())($account);

        self::assertCount(2, $this->audited);
        self::assertSame(AuditAction::EMAIL_FORWARDING_ROTATED, $this->audited[1]['action']);
        self::assertSame($previous, $this->audited[1]['metadata']['previous']);
    }

    public function testRotatingWithoutForwardingIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no tiene activada la ingesta por reenvío');

        ($this->rotate())($this->account);
    }

    public function testDisablingStopsAcceptingMailButKeepsTheAddress(): void
    {
        $account = ($this->enable())($this->account, ['yo@ovh.com']);
        $address = $account->getForwardingAddress();

        ($this->disable())($account);

        self::assertFalse($account->isForwardingEnabled());
        self::assertSame($address, $account->getForwardingAddress());
        self::assertSame(AuditAction::EMAIL_FORWARDING_DISABLED, $this->audited[1]['action']);
    }

    /**
     * La dirección desactivada deja de resolver, que es lo que impide que siga
     * entrando correo.
     */
    public function testADisabledAddressNoLongerResolves(): void
    {
        $account = ($this->enable())($this->account, ['yo@ovh.com']);
        $address = (string) $account->getForwardingAddress();

        self::assertNotNull($this->accounts->findByForwardingAddress($address));

        ($this->disable())($account);

        self::assertNull($this->accounts->findByForwardingAddress($address));
    }

    public function testTheSendersCanBeUpdated(): void
    {
        $account = ($this->enable())($this->account, ['yo@ovh.com']);

        ($this->updateSenders())($account, ['@ovh.com']);

        self::assertSame(['@ovh.com'], $account->getForwardingSenders());
        self::assertSame(AuditAction::EMAIL_FORWARDING_SENDERS_UPDATED, $this->audited[1]['action']);
    }

    public function testUpdatingSendersWithoutForwardingIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ($this->updateSenders())($this->account, ['yo@ovh.com']);
    }

    public function testAnInvalidSenderIsRejectedBeforeSaving(): void
    {
        $account = ($this->enable())($this->account, ['yo@ovh.com']);

        try {
            ($this->updateSenders())($account, ['no es un correo']);
            self::fail('Se esperaba un rechazo.');
        } catch (InvalidArgumentException) {
            // La lista anterior sigue intacta.
        }

        self::assertSame(['yo@ovh.com'], $account->getForwardingSenders());
    }

    public function testTheAddressIsNotDerivedFromTheAccount(): void
    {
        $account = ($this->enable())($this->account, ['yo@ovh.com']);

        self::assertStringNotContainsString('ovh', (string) $account->getForwardingAddress());
    }
}
