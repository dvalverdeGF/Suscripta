<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain\Entity;

use App\Catalog\Domain\Entity\Provider;
use App\Catalog\Domain\Entity\ProviderIdentity;
use App\Catalog\Domain\Enum\ProviderIdentityType;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderIdentity::class)]
#[CoversClass(ProviderIdentityType::class)]
final class ProviderIdentityTest extends TestCase
{
    private Provider $provider;

    protected function setUp(): void
    {
        $this->provider = new Provider('OVHcloud', 'ovhcloud', system: true);
    }

    public function testDomainsAndAddressesAreNormalisedToLowerCase(): void
    {
        $domain = new ProviderIdentity($this->provider, ProviderIdentityType::DOMAIN, '  OVH.COM  ');
        $sender = new ProviderIdentity($this->provider, ProviderIdentityType::SENDER, 'Facturas@OVH.com');

        self::assertSame('ovh.com', $domain->getValue());
        self::assertSame('facturas@ovh.com', $sender->getValue());
    }

    /**
     * Un patrón es una expresión regular, no un dominio: normalizarlo a
     * minúsculas lo rompe en silencio.
     */
    public function testPatternsKeepTheirCase(): void
    {
        $subject = new ProviderIdentity($this->provider, ProviderIdentityType::SUBJECT_PATTERN, '  /^Tu factura de OVH/  ');
        $attachment = new ProviderIdentity($this->provider, ProviderIdentityType::ATTACHMENT_PATTERN, '/^OVH-Facture/i');

        self::assertSame('/^Tu factura de OVH/', $subject->getValue());
        self::assertSame('/^OVH-Facture/i', $attachment->getValue());
    }

    public function testOnlyPatternTypesArePatterns(): void
    {
        self::assertFalse(ProviderIdentityType::DOMAIN->isPattern());
        self::assertFalse(ProviderIdentityType::SENDER->isPattern());
        self::assertTrue(ProviderIdentityType::SUBJECT_PATTERN->isPattern());
        self::assertTrue(ProviderIdentityType::ATTACHMENT_PATTERN->isPattern());
    }

    public function testAHitRaisesTheConfidenceUpToTheCeiling(): void
    {
        $identity = new ProviderIdentity($this->provider, ProviderIdentityType::DOMAIN, 'ovh.com', confidence: 100);

        $identity->recordHit(new DateTimeImmutable('2026-10-05 10:00:00'));

        self::assertSame(1, $identity->getHitCount());
        self::assertSame(100, $identity->getConfidence());
        self::assertSame('2026-10-05 10:00:00', $identity->getLastSeenAt()?->format('Y-m-d H:i:s'));
    }

    public function testTheConfidenceIsClampedOnConstruction(): void
    {
        $low = new ProviderIdentity($this->provider, ProviderIdentityType::DOMAIN, 'a.com', confidence: -10);
        $high = new ProviderIdentity($this->provider, ProviderIdentityType::DOMAIN, 'b.com', confidence: 500);

        self::assertSame(0, $low->getConfidence());
        self::assertSame(100, $high->getConfidence());
    }
}
