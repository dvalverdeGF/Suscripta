<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Domain\Entity;

use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryConfidence;
use App\Discovery\Domain\Enum\DiscoveryStatus;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * `Discovery` es la materialización de la regla de producto: el sistema
 * propone, el usuario decide. Nunca se crea un `Service` automáticamente.
 */
#[CoversClass(Discovery::class)]
final class DiscoveryTest extends TestCase
{
    /**
     * @param array<string, mixed> $proposedData
     */
    private function discovery(array $proposedData = []): Discovery
    {
        return new Discovery(
            organizationId: Uuid::v7(),
            type: DiscoveryType::NEW_SERVICE,
            detectedAt: new DateTimeImmutable('2026-10-05 10:00:00'),
            proposedData: $proposedData ?: [
                'providerName' => 'OVH',
                'amountMinor' => 2990,
                'currency' => 'EUR',
                'billingPeriod' => 'monthly',
            ],
        );
    }

    public function testStartsPendingAndLowConfidence(): void
    {
        $discovery = $this->discovery();

        self::assertSame(DiscoveryStatus::PENDING, $discovery->getStatus());
        self::assertSame(DiscoveryConfidence::LOW, $discovery->getConfidence());
        self::assertSame(0, $discovery->getConfidenceScore());
        self::assertSame(0, $discovery->getMatchScore());
        self::assertFalse($discovery->isAiUsed());
        self::assertNull($discovery->getReviewedAt());
    }

    public function testDedupKeyIsStableForTheSameProposal(): void
    {
        $data = [
            'providerName' => 'OVH',
            'amountMinor' => 2990,
            'currency' => 'EUR',
            'billingPeriod' => 'monthly',
        ];

        self::assertSame(
            Discovery::buildDedupKey(DiscoveryType::NEW_SERVICE, $data),
            $this->discovery($data)->getDedupKey(),
        );
    }

    /**
     * Dos buzones que reciben la misma factura no deben generar dos propuestas
     * (D-27), así que la clave no puede depender del mensaje de origen.
     */
    public function testDedupKeyIgnoresSourceMessage(): void
    {
        $a = $this->discovery();
        $b = $this->discovery();

        $a->attachSourceMessage(Uuid::v7());
        $b->attachSourceMessage(Uuid::v7());

        self::assertSame($a->getDedupKey(), $b->getDedupKey());
    }

    public function testDedupKeyDistinguishesTypeAndAmount(): void
    {
        $data = ['providerName' => 'OVH', 'amountMinor' => 2990, 'currency' => 'EUR', 'billingPeriod' => 'monthly'];

        self::assertNotSame(
            Discovery::buildDedupKey(DiscoveryType::NEW_SERVICE, $data),
            Discovery::buildDedupKey(DiscoveryType::PRICE_CHANGE, $data),
        );

        self::assertNotSame(
            Discovery::buildDedupKey(DiscoveryType::NEW_SERVICE, $data),
            Discovery::buildDedupKey(DiscoveryType::NEW_SERVICE, ['providerName' => 'OVH', 'amountMinor' => 3990, 'currency' => 'EUR', 'billingPeriod' => 'monthly']),
        );
    }

    public function testDedupKeyIsTruncatedToTheColumnLength(): void
    {
        $key = Discovery::buildDedupKey(DiscoveryType::NEW_SERVICE, [
            'providerName' => str_repeat('x', 400),
            'amountMinor' => 1,
            'currency' => 'EUR',
            'billingPeriod' => 'monthly',
        ]);

        self::assertLessThanOrEqual(255, mb_strlen($key));
    }

    public function testUpdateProposalRecomputesTheDedupKey(): void
    {
        $discovery = $this->discovery();
        $before = $discovery->getDedupKey();

        $discovery->updateProposal([
            'providerName' => 'OVH',
            'amountMinor' => 4990,
            'currency' => 'EUR',
            'billingPeriod' => 'monthly',
        ]);

        self::assertNotSame($before, $discovery->getDedupKey());
    }

    public function testApplyMatchClampsTheScore(): void
    {
        $discovery = $this->discovery();

        $discovery->applyMatch(150, [['signal' => 'Proveedor identificado', 'weight' => 40]]);
        self::assertSame(100, $discovery->getMatchScore());

        $discovery->applyMatch(-20, []);
        self::assertSame(0, $discovery->getMatchScore());
    }

    public function testApplyConfidenceDerivesTheBucket(): void
    {
        $discovery = $this->discovery();

        $discovery->applyConfidence(85);
        self::assertSame(DiscoveryConfidence::HIGH, $discovery->getConfidence());

        $discovery->applyConfidence(55);
        self::assertSame(DiscoveryConfidence::MEDIUM, $discovery->getConfidence());

        $discovery->applyConfidence(20);
        self::assertSame(DiscoveryConfidence::LOW, $discovery->getConfidence());
    }

    public function testRecordExtractionKeepsTheTierAndWhetherAiWasUsed(): void
    {
        $discovery = $this->discovery();

        $discovery->recordExtraction(ExtractionTier::DETERMINISTIC);
        self::assertSame(ExtractionTier::DETERMINISTIC, $discovery->getExtractionTier());
        self::assertFalse($discovery->isAiUsed());

        $discovery->recordExtraction(ExtractionTier::AI_CHEAP, true);
        self::assertTrue($discovery->isAiUsed());
    }

    public function testConfirmMarksItConfirmedAndRecordsTheResultingService(): void
    {
        $discovery = $this->discovery();
        $user = Uuid::v7();
        $service = Uuid::v7();

        $discovery->confirm($user, new DateTimeImmutable('2026-10-06 09:00:00'), $service);

        self::assertSame(DiscoveryStatus::CONFIRMED, $discovery->getStatus());
        self::assertSame($user, $discovery->getReviewedByUserId());
        self::assertSame($service, $discovery->getResultingServiceId());
        self::assertNotNull($discovery->getReviewedAt());
    }

    /**
     * `edited` es la métrica de precisión del pipeline: mide cuántas veces el
     * usuario tuvo que corregir la propuesta.
     */
    public function testConfirmWithEditsIsRecordedAsEdited(): void
    {
        $discovery = $this->discovery();

        $discovery->confirm(Uuid::v7(), new DateTimeImmutable('2026-10-06 09:00:00'), Uuid::v7(), edited: true);

        self::assertSame(DiscoveryStatus::EDITED, $discovery->getStatus());
    }

    public function testIgnoreKeepsTheReasonAsLearningInput(): void
    {
        $discovery = $this->discovery();

        $discovery->ignore(Uuid::v7(), new DateTimeImmutable('2026-10-06 09:00:00'), '  Ya no uso este servicio  ');

        self::assertSame(DiscoveryStatus::IGNORED, $discovery->getStatus());
        self::assertSame('Ya no uso este servicio', $discovery->getNotes());
    }

    public function testCannotReviewTwice(): void
    {
        $discovery = $this->discovery();
        $discovery->confirm(Uuid::v7(), new DateTimeImmutable('2026-10-06 09:00:00'), Uuid::v7());

        $this->expectException(InvalidArgumentException::class);

        $discovery->ignore(Uuid::v7(), new DateTimeImmutable('2026-10-07 09:00:00'));
    }

    public function testExpireOnlyAffectsPendingProposals(): void
    {
        $pending = $this->discovery();
        $pending->expire(new DateTimeImmutable('2026-11-05 10:00:00'));
        self::assertSame(DiscoveryStatus::EXPIRED, $pending->getStatus());

        $confirmed = $this->discovery();
        $confirmed->confirm(Uuid::v7(), new DateTimeImmutable('2026-10-06 09:00:00'), Uuid::v7());
        $confirmed->expire(new DateTimeImmutable('2026-11-05 10:00:00'));

        self::assertSame(DiscoveryStatus::CONFIRMED, $confirmed->getStatus());
    }

    public function testNotesAreTrimmedToNull(): void
    {
        $discovery = $this->discovery();

        $discovery->setNotes('   ');

        self::assertNull($discovery->getNotes());
    }
}
