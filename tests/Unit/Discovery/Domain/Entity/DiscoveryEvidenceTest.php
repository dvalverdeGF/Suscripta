<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Domain\Entity;

use App\Discovery\Domain\Entity\DiscoveryEvidence;
use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * La evidencia responde a "¿de dónde te sacas esto?".
 *
 * Si una propuesta no apunta a ningún correo ni documento, es una afirmación
 * sin respaldo: por eso la entidad **exige** al menos un origen en el
 * constructor, en lugar de dejar que el pipeline la cree vacía.
 */
#[CoversClass(DiscoveryEvidence::class)]
final class DiscoveryEvidenceTest extends TestCase
{
    public function testItAcceptsAnEmailAsTheOnlySource(): void
    {
        $emailId = Uuid::v7();

        $evidence = new DiscoveryEvidence(Uuid::v7(), new DateTimeImmutable(), $emailId);

        self::assertTrue($emailId->equals($evidence->getEmailMessageId()));
        self::assertNull($evidence->getDocumentId());
    }

    public function testItAcceptsADocumentAsTheOnlySource(): void
    {
        $documentId = Uuid::v7();

        $evidence = new DiscoveryEvidence(Uuid::v7(), new DateTimeImmutable(), null, $documentId);

        self::assertTrue($documentId->equals($evidence->getDocumentId()));
        self::assertNull($evidence->getEmailMessageId());
    }

    public function testItAcceptsBothSourcesAtOnce(): void
    {
        $emailId = Uuid::v7();
        $documentId = Uuid::v7();

        $evidence = new DiscoveryEvidence(Uuid::v7(), new DateTimeImmutable(), $emailId, $documentId);

        self::assertTrue($emailId->equals($evidence->getEmailMessageId()));
        self::assertTrue($documentId->equals($evidence->getDocumentId()));
    }

    public function testItRefusesEvidenceWithoutAnySource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Una evidencia debe apuntar al menos a un correo o a un documento.');

        new DiscoveryEvidence(Uuid::v7(), new DateTimeImmutable());
    }

    public function testTheWeightIsClampedToTheHundred(): void
    {
        $evidence = new DiscoveryEvidence(Uuid::v7(), new DateTimeImmutable(), Uuid::v7(), null, 250);

        self::assertSame(100, $evidence->getWeight());
    }

    public function testTheWeightCannotGoBelowZero(): void
    {
        $evidence = new DiscoveryEvidence(Uuid::v7(), new DateTimeImmutable(), Uuid::v7(), null, -10);

        self::assertSame(0, $evidence->getWeight());
    }

    public function testTheDefaultWeightIsFull(): void
    {
        $evidence = new DiscoveryEvidence(Uuid::v7(), new DateTimeImmutable(), Uuid::v7());

        self::assertSame(100, $evidence->getWeight());
    }

    public function testItKeepsTheDiscoveryItBelongsTo(): void
    {
        $discoveryId = Uuid::v7();

        $evidence = new DiscoveryEvidence($discoveryId, new DateTimeImmutable(), Uuid::v7());

        self::assertTrue($discoveryId->equals($evidence->getDiscoveryId()));
    }
}
