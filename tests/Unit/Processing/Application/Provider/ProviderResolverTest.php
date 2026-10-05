<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Provider;

use App\Catalog\Domain\Entity\Provider;
use App\Catalog\Domain\Entity\ProviderIdentity;
use App\Catalog\Domain\Enum\ProviderIdentityType;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Processing\Application\Provider\ProviderResolver;
use App\Shared\Application\Clock;
use App\Tests\Support\Catalog\InMemoryProviderIdentityRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function str_repeat;

#[CoversClass(ProviderResolver::class)]
final class ProviderResolverTest extends TestCase
{
    private InMemoryProviderIdentityRepository $identities;
    private ProviderResolver $resolver;
    private Provider $ovh;

    protected function setUp(): void
    {
        $this->identities = new InMemoryProviderIdentityRepository();
        $this->resolver = new ProviderResolver($this->identities, new Clock(new MockClock(new DateTimeImmutable('2026-10-05 10:00:00'))));
        $this->ovh = new Provider('OVHcloud', 'ovhcloud', system: true);
    }

    public function testItResolvesBySenderAddressFirst(): void
    {
        $this->identities->add(new ProviderIdentity($this->ovh, ProviderIdentityType::SENDER, 'facturas@ovh.com'));
        $this->identities->add(new ProviderIdentity($this->ovh, ProviderIdentityType::DOMAIN, 'ovh.com'));

        $match = $this->resolver->resolve($this->header(fromAddress: 'facturas@ovh.com'));

        self::assertTrue($match->isKnown());
        self::assertSame('sender', $match->matchedBy);
        self::assertSame('OVHcloud', $match->displayName());
    }

    public function testItFallsBackToTheDomain(): void
    {
        $this->identities->add(new ProviderIdentity($this->ovh, ProviderIdentityType::DOMAIN, 'ovh.com'));

        $match = $this->resolver->resolve($this->header(fromAddress: 'no-reply@ovh.com'));

        self::assertTrue($match->isKnown());
        self::assertSame('domain', $match->matchedBy);
    }

    public function testItFallsBackToTheSubjectPattern(): void
    {
        $this->identities->add(new ProviderIdentity($this->ovh, ProviderIdentityType::SUBJECT_PATTERN, '/^Tu factura de OVH/'));

        $match = $this->resolver->resolve($this->header(fromAddress: 'cobros@pasarela.com', subject: 'Tu factura de OVH 2026-10'));

        self::assertTrue($match->isKnown());
        self::assertSame('subject_pattern', $match->matchedBy);
    }

    public function testItFallsBackToTheAttachmentPattern(): void
    {
        $this->identities->add(new ProviderIdentity($this->ovh, ProviderIdentityType::ATTACHMENT_PATTERN, '/^ovh-facture/i'));

        $match = $this->resolver->resolve($this->header(
            fromAddress: 'cobros@pasarela.com',
            subject: 'Tu recibo',
            attachmentNames: ['ovh-facture-2026-10.pdf'],
        ));

        self::assertTrue($match->isKnown());
        self::assertSame('attachment_pattern', $match->matchedBy);
    }

    public function testTheSenderWinsOverTheDomain(): void
    {
        $other = new Provider('Pasarela', 'pasarela', system: true);
        $this->identities->add(new ProviderIdentity($other, ProviderIdentityType::SENDER, 'facturas@ovh.com'));
        $this->identities->add(new ProviderIdentity($this->ovh, ProviderIdentityType::DOMAIN, 'ovh.com'));

        $match = $this->resolver->resolve($this->header(fromAddress: 'facturas@ovh.com'));

        self::assertSame('Pasarela', $match->displayName());
    }

    public function testAnUnknownSenderGetsAProvisionalNameFromTheDisplayName(): void
    {
        $match = $this->resolver->resolve($this->header(fromAddress: 'facturas@nuevo.com', fromName: 'Nuevo Proveedor S.L.'));

        self::assertFalse($match->isKnown());
        self::assertSame('Nuevo Proveedor S.L.', $match->displayName());
        self::assertSame('sender', $match->matchedBy);
        self::assertSame(40, $match->confidence);
    }

    public function testTheProvisionalNameFallsBackToTheDomain(): void
    {
        $match = $this->resolver->resolve($this->header(fromAddress: 'facturas@nuevo.com'));

        self::assertFalse($match->isKnown());
        self::assertSame('nuevo.com', $match->displayName());
    }

    public function testTheProvisionalNameIsTruncated(): void
    {
        $match = $this->resolver->resolve($this->header(fromAddress: 'a@nuevo.com', fromName: str_repeat('x', 200)));

        self::assertSame(120, mb_strlen((string) $match->displayName()));
    }

    public function testAMessageWithoutASenderIsUnknown(): void
    {
        $match = $this->resolver->resolve($this->header(fromAddress: null));

        self::assertFalse($match->isKnown());
        self::assertNull($match->displayName());
        self::assertNull($match->matchedBy);
    }

    public function testAMatchReinforcesTheIdentity(): void
    {
        $identity = new ProviderIdentity($this->ovh, ProviderIdentityType::DOMAIN, 'ovh.com', confidence: 80);
        $this->identities->add($identity);

        $this->resolver->resolve($this->header(fromAddress: 'no-reply@ovh.com'));

        self::assertSame(1, $identity->getHitCount());
        self::assertSame(81, $identity->getConfidence());
        self::assertNotNull($identity->getLastSeenAt());
    }

    public function testAMalformedPatternDoesNotBreakTheResolution(): void
    {
        $this->identities->add(new ProviderIdentity($this->ovh, ProviderIdentityType::SUBJECT_PATTERN, '/esto no cierra'));

        $match = $this->resolver->resolve($this->header(fromAddress: 'a@nuevo.com', subject: 'Factura'));

        self::assertFalse($match->isKnown());
        self::assertSame('nuevo.com', $match->displayName());
    }

    public function testTheAttachmentPatternsAreReadOnlyOncePerMessage(): void
    {
        $this->identities->add(new ProviderIdentity($this->ovh, ProviderIdentityType::ATTACHMENT_PATTERN, '/^ovh-facture/i'));

        $match = $this->resolver->resolve($this->header(
            fromAddress: 'cobros@pasarela.com',
            attachmentNames: ['a.pdf', 'b.pdf', 'c.pdf', 'ovh-facture-2026-10.pdf'],
        ));

        self::assertTrue($match->isKnown());
        self::assertSame(1, $this->identities->findByTypeCallsByType['attachment_pattern'] ?? 0);
    }

    public function testAMessageWithoutAttachmentsDoesNotReadTheAttachmentPatterns(): void
    {
        $this->resolver->resolve($this->header(fromAddress: 'a@nuevo.com'));

        self::assertArrayNotHasKey('attachment_pattern', $this->identities->findByTypeCallsByType);
    }

    /**
     * @param list<string> $attachmentNames
     */
    private function header(
        ?string $fromAddress = 'facturas@ovh.com',
        ?string $fromName = null,
        string $subject = 'Tu factura',
        array $attachmentNames = [],
    ): ImapMessageHeader {
        return new ImapMessageHeader(
            uid: 1,
            messageId: '<x@y>',
            fromAddress: $fromAddress,
            fromName: $fromName,
            replyTo: null,
            toAddresses: ['yo@miempresa.com'],
            subject: $subject,
            receivedAt: new DateTimeImmutable('2026-10-01 09:00:00'),
            sizeBytes: 1000,
            contentType: 'text/plain',
            attachmentNames: $attachmentNames,
            attachmentTypes: [],
        );
    }
}
