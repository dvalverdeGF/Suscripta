<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Knowledge;

use App\Catalog\Domain\Entity\Provider;
use App\Catalog\Domain\Entity\ProviderIdentity;
use App\Catalog\Domain\Enum\ProviderIdentitySource;
use App\Catalog\Domain\Enum\ProviderIdentityType;
use App\Catalog\Domain\Repository\ProviderIdentityRepositoryInterface;
use App\Catalog\Domain\Repository\ProviderRepositoryInterface;
use App\Processing\Application\Knowledge\PromoteMailboxKnowledge;
use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Processing\Domain\Enum\MailboxKnowledgeSource;
use App\Processing\Domain\Enum\MailboxKnowledgeType;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * La promoción de conocimiento del buzón al catálogo global (D-33).
 *
 * La promoción **nunca es automática**: el sistema no sube al catálogo global
 * lo que ha aprendido de un buzón por su cuenta, porque un patrón equivocado
 * en el buzón de un usuario se convertiría en un error para todos. Y solo se
 * puede promover hacia un proveedor del catálogo global: promover un
 * proveedor del tenant filtraría datos de un usuario al resto.
 */
#[CoversClass(PromoteMailboxKnowledge::class)]
final class PromoteMailboxKnowledgeTest extends TestCase
{
    private const NOW = '2026-10-05 10:00:00';

    private Uuid $accountId;
    private Uuid $organizationId;
    private Provider $globalProvider;

    private MailboxKnowledgeRepositoryInterface&MockObject $entries;
    private ProviderIdentityRepositoryInterface&MockObject $identities;
    private ProviderRepositoryInterface&MockObject $providers;
    private PromoteMailboxKnowledge $promoter;

    protected function setUp(): void
    {
        $this->accountId = Uuid::v7();
        $this->organizationId = Uuid::v7();
        $this->globalProvider = new Provider('OVHcloud', 'ovhcloud');

        $this->entries = $this->createMock(MailboxKnowledgeRepositoryInterface::class);
        $this->identities = $this->createMock(ProviderIdentityRepositoryInterface::class);
        $this->identities->method('findByTypeAndValue')->willReturn(null);
        $this->providers = $this->createMock(ProviderRepositoryInterface::class);
        $this->providers->method('find')->willReturn($this->globalProvider);

        $this->promoter = new PromoteMailboxKnowledge(
            $this->entries,
            $this->identities,
            $this->providers,
            new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );
    }

    public function testItPromotesAnEmailSenderAsASenderIdentity(): void
    {
        $entry = $this->entry(MailboxKnowledgeType::SENDER_MAPPING, 'facturacion@ovh.com');

        $identity = ($this->promoter)($entry);

        self::assertSame(ProviderIdentityType::SENDER, $identity->getType());
        self::assertSame('facturacion@ovh.com', $identity->getValue());
        self::assertSame(ProviderIdentitySource::LEARNED, $identity->getSource());
    }

    public function testItPromotesADomainAsADomainIdentity(): void
    {
        $entry = $this->entry(MailboxKnowledgeType::PROVIDER_HINT, 'ovh.com');

        $identity = ($this->promoter)($entry);

        self::assertSame(ProviderIdentityType::DOMAIN, $identity->getType());
        self::assertSame('ovh.com', $identity->getValue());
    }

    public function testTheEntryIsMarkedAsPromoted(): void
    {
        $entry = $this->entry(MailboxKnowledgeType::SENDER_MAPPING, 'facturacion@ovh.com');

        ($this->promoter)($entry);

        self::assertTrue($entry->isPromoted());
        self::assertNotNull($entry->getPromotedAt());
    }

    public function testItRefusesToPromoteSomethingThatIsNotAProvider(): void
    {
        $entry = $this->entry(MailboxKnowledgeType::SUBJECT_PATTERN, '/^Factura/u');

        $this->expectException(InvalidArgumentException::class);

        ($this->promoter)($entry);
    }

    public function testItRefusesToPromoteAnEntryWithoutAProvider(): void
    {
        $entry = new MailboxKnowledgeEntry(
            emailAccountId: $this->accountId,
            organizationId: $this->organizationId,
            type: MailboxKnowledgeType::SENDER_MAPPING,
            value: 'facturacion@ovh.com',
            now: new DateTimeImmutable(self::NOW),
        );

        $this->expectException(InvalidArgumentException::class);

        ($this->promoter)($entry);
    }

    public function testItRefusesToPromoteATenantProvider(): void
    {
        $tenantProvider = new Provider('Proveedor del tenant', 'tenant', $this->organizationId);
        $this->providers = $this->createMock(ProviderRepositoryInterface::class);
        $this->providers->method('find')->willReturn($tenantProvider);

        $promoter = new PromoteMailboxKnowledge(
            $this->entries,
            $this->identities,
            $this->providers,
            new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );

        $entry = $this->entry(MailboxKnowledgeType::SENDER_MAPPING, 'facturacion@ovh.com');

        $this->expectException(InvalidArgumentException::class);

        $promoter($entry);
    }

    public function testItReusesTheIdentityThatAlreadyExists(): void
    {
        $existing = new ProviderIdentity(
            provider: $this->globalProvider,
            type: ProviderIdentityType::SENDER,
            value: 'facturacion@ovh.com',
        );

        $this->identities = $this->createMock(ProviderIdentityRepositoryInterface::class);
        $this->identities->method('findByTypeAndValue')->willReturn($existing);
        $this->identities->expects(self::never())->method('save');

        $promoter = new PromoteMailboxKnowledge(
            $this->entries,
            $this->identities,
            $this->providers,
            new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );

        $entry = $this->entry(MailboxKnowledgeType::SENDER_MAPPING, 'facturacion@ovh.com');

        $identity = $promoter($entry);

        self::assertSame($existing, $identity);
        self::assertTrue($entry->isPromoted());
    }

    private function entry(MailboxKnowledgeType $type, string $value): MailboxKnowledgeEntry
    {
        return new MailboxKnowledgeEntry(
            emailAccountId: $this->accountId,
            organizationId: $this->organizationId,
            type: $type,
            value: $value,
            providerId: $this->globalProvider->getId(),
            source: MailboxKnowledgeSource::OBSERVED,
            confidence: 80,
            now: new DateTimeImmutable(self::NOW),
        );
    }
}
