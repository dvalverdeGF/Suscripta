<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documents\Domain\Entity;

use App\Documents\Domain\Entity\Document;
use App\Documents\Domain\Enum\DocumentSource;
use App\Documents\Domain\Enum\DocumentType;
use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;

use function mb_strlen;

use PHPUnit\Framework\TestCase;

use function str_repeat;

use Symfony\Component\Uid\Uuid;

/**
 * El documento es la evidencia de lo que se paga. Sus invariantes importan
 * porque un documento mal formado hace que la huella deje de ser fiable y, con
 * ella, la deduplicación entre buzones (D-27).
 */
final class DocumentTest extends TestCase
{
    private const CHECKSUM = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function testItStartsWithoutServiceOrInvoice(): void
    {
        $document = $this->document();

        self::assertNull($document->getServiceId());
        self::assertNull($document->getInvoiceId());
        self::assertNull($document->getEmailMessageId());
        self::assertFalse($document->isDeleted());
        self::assertNull($document->getDeletedAt());
    }

    public function testItRejectsAnEmptyFilename(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->document(filename: '');
    }

    public function testItRejectsAnEmptyStorageKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->document(storageKey: '');
    }

    public function testItRejectsANegativeSize(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->document(sizeBytes: -1);
    }

    public function testItRejectsAChecksumThatIsNotSha256(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->document(checksum: 'abc');
    }

    public function testItTruncatesAnOverlongFilename(): void
    {
        $document = $this->document(filename: str_repeat('a', 300).'.pdf');

        self::assertSame(255, mb_strlen($document->getOriginalFilename()));
    }

    public function testItAttachesToAService(): void
    {
        $document = $this->document();
        $serviceId = Uuid::v7();

        $document->attachToService($serviceId);

        self::assertSame($serviceId->toRfc4122(), $document->getServiceId()?->toRfc4122());
    }

    public function testItCanBeDetachedFromAService(): void
    {
        $document = $this->document();
        $document->attachToService(Uuid::v7());

        $document->attachToService(null);

        self::assertNull($document->getServiceId());
    }

    public function testItLinksToAnInvoiceAndAnEmailMessage(): void
    {
        $document = $this->document();
        $invoiceId = Uuid::v7();
        $messageId = Uuid::v7();

        $document->attachToInvoice($invoiceId);
        $document->linkToEmailMessage($messageId);

        self::assertSame($invoiceId->toRfc4122(), $document->getInvoiceId()?->toRfc4122());
        self::assertSame($messageId->toRfc4122(), $document->getEmailMessageId()?->toRfc4122());
    }

    public function testDeletingIsSoftAndReversible(): void
    {
        $document = $this->document();
        $at = new DateTimeImmutable('2026-10-05 10:00:00');

        $document->delete($at);

        self::assertTrue($document->isDeleted());
        self::assertSame($at->format('c'), $document->getDeletedAt()?->format('c'));

        $document->restore();

        self::assertFalse($document->isDeleted());
        self::assertNull($document->getDeletedAt());
    }

    public function testItChangesType(): void
    {
        $document = $this->document();

        $document->setType(DocumentType::CONTRACT);

        self::assertSame(DocumentType::CONTRACT, $document->getType());
    }

    public function testDisplayNameCombinesTheFilenameAndTheSize(): void
    {
        $document = $this->document(filename: 'factura-ovh.pdf', sizeBytes: 512);

        self::assertSame('factura-ovh.pdf (512 B)', $document->displayName());
    }

    public function testHumanSizeIsReadable(): void
    {
        self::assertSame('512 B', $this->document(sizeBytes: 512)->humanSize());
        self::assertSame('1.0 KB', $this->document(sizeBytes: 1024)->humanSize());
        self::assertSame('1.0 MB', $this->document(sizeBytes: 1024 * 1024)->humanSize());
    }

    public function testItKnowsWhetherItDependsOnAMailbox(): void
    {
        self::assertTrue($this->document(source: DocumentSource::EMAIL_ATTACHMENT)->getSource()->dependsOnMailbox());
        self::assertTrue($this->document(source: DocumentSource::EMAIL_BODY)->getSource()->dependsOnMailbox());
        self::assertFalse($this->document(source: DocumentSource::MANUAL_UPLOAD)->getSource()->dependsOnMailbox());
    }

    private function document(
        string $filename = 'factura.pdf',
        string $storageKey = 'org/ab/cd/hash.pdf',
        int $sizeBytes = 1024,
        string $checksum = self::CHECKSUM,
        DocumentType $type = DocumentType::INVOICE,
        DocumentSource $source = DocumentSource::MANUAL_UPLOAD,
    ): Document {
        return new Document(
            organizationId: Uuid::v7(),
            originalFilename: $filename,
            storageDriver: 'local',
            storageKey: $storageKey,
            mimeType: 'application/pdf',
            sizeBytes: $sizeBytes,
            checksumSha256: $checksum,
            type: $type,
            source: $source,
        );
    }
}
