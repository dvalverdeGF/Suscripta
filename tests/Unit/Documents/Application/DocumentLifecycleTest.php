<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documents\Application;

use App\Documents\Application\AttachDocumentToService;
use App\Documents\Application\DeleteDocument;
use App\Documents\Application\DownloadDocument;
use App\Documents\Domain\Entity\Document;
use App\Documents\Domain\Enum\DocumentSource;
use App\Documents\Domain\Enum\DocumentType;
use App\Documents\Domain\Exception\DocumentStorageException;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Enum\ServiceSource;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Tests\Support\Documents\InMemoryDocumentRepository;
use App\Tests\Support\Documents\InMemoryDocumentStorage;
use App\Tests\Support\Services\ServiceEventAssertions;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function str_repeat;

use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * El ciclo de vida de un documento: leerlo, borrarlo y vincularlo.
 *
 * Dos reglas que se comprueban aquí y que son de seguridad, no de comodidad:
 * un documento borrado **no se puede descargar**, y toda lectura queda
 * auditada (SECURITY.md §1 y §7).
 */
final class DocumentLifecycleTest extends TestCase
{
    use ServiceEventAssertions;

    private Uuid $organizationId;
    private InMemoryDocumentRepository $documents;
    private InMemoryDocumentStorage $storage;
    private ServiceRepositoryInterface&MockObject $services;
    private AuditLoggerInterface&MockObject $auditLogger;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->documents = new InMemoryDocumentRepository();
        $this->storage = new InMemoryDocumentStorage();
        $this->services = $this->createMock(ServiceRepositoryInterface::class);
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->now = new DateTimeImmutable('2026-10-05 09:00:00');
    }

    public function testItReadsTheStoredContents(): void
    {
        $document = $this->storedDocument('el binario');

        $result = $this->download($document->getId());

        self::assertSame('el binario', $result->contents);
        self::assertSame($document->getId()->toRfc4122(), $result->document->getId()->toRfc4122());
    }

    public function testItAuditsEveryDownload(): void
    {
        $document = $this->storedDocument('x');

        $this->auditLogger
            ->expects(self::once())
            ->method('log')
            ->with(
                self::equalTo(AuditAction::DOCUMENT_DOWNLOADED),
                self::equalTo('document'),
                self::isType('string'),
                self::isType('array'),
                self::anything(),
            );

        $this->download($document->getId());
    }

    public function testDownloadingAnUnknownDocumentThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->download(Uuid::v7());
    }

    public function testADeletedDocumentCannotBeDownloaded(): void
    {
        $document = $this->storedDocument('x');
        $document->delete($this->now);
        $this->documents->save($document);

        $this->expectException(InvalidArgumentException::class);

        $this->download($document->getId());
    }

    public function testAMissingBinaryIsReportedAsAStorageFailure(): void
    {
        $document = $this->document();
        $this->documents->save($document);

        $this->expectException(DocumentStorageException::class);

        $this->download($document->getId());
    }

    public function testDeletingIsSoftAndKeepsTheBinary(): void
    {
        $document = $this->storedDocument('x');

        $this->delete($document->getId());

        self::assertTrue($document->isDeleted());
        self::assertSame($this->now->format('c'), $document->getDeletedAt()?->format('c'));
        self::assertTrue($this->storage->exists($document->getStorageKey()));
        self::assertSame([], $this->storage->deleted);
    }

    public function testDeletingTwiceIsIdempotent(): void
    {
        $document = $this->storedDocument('x');

        $this->delete($document->getId());
        $this->delete($document->getId());

        self::assertTrue($document->isDeleted());
    }

    public function testDeletingAuditsOnce(): void
    {
        $document = $this->storedDocument('x');

        $this->auditLogger
            ->expects(self::once())
            ->method('log')
            ->with(
                self::equalTo(AuditAction::DOCUMENT_DELETED),
                self::equalTo('document'),
                self::isType('string'),
                self::isType('array'),
                self::anything(),
            );

        $this->delete($document->getId());
        $this->delete($document->getId());
    }

    public function testDeletingAnUnknownDocumentThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->delete(Uuid::v7());
    }

    public function testItAttachesADocumentToAServiceAndRecordsTheEvent(): void
    {
        $document = $this->storedDocument('x');
        $service = $this->service();
        $this->services->method('find')->willReturn($service);

        $this->attach($document->getId(), $service->getId());

        self::assertSame($service->getId()->toRfc4122(), $document->getServiceId()?->toRfc4122());
        self::assertCount(1, $service->getEvents());
        self::assertSame(ServiceEventType::DOCUMENT_ADDED, self::eventAt($service, 0)->getType());
    }

    public function testItDetachesADocumentWithoutRecordingAnEvent(): void
    {
        $document = $this->storedDocument('x');
        $service = $this->service();
        $this->services->method('find')->willReturn($service);
        $this->attach($document->getId(), $service->getId());

        $this->attach($document->getId(), null);

        self::assertNull($document->getServiceId());
        self::assertCount(1, $service->getEvents());
    }

    public function testItRefusesToAttachToAnUnknownService(): void
    {
        $document = $this->storedDocument('x');
        $this->services->method('find')->willReturn(null);

        $this->expectException(InvalidArgumentException::class);

        $this->attach($document->getId(), Uuid::v7());
    }

    public function testItRefusesToAttachADeletedDocument(): void
    {
        $document = $this->storedDocument('x');
        $document->delete($this->now);
        $this->documents->save($document);

        $this->expectException(InvalidArgumentException::class);

        $this->attach($document->getId(), null);
    }

    public function testItRefusesToAttachAnUnknownDocument(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->attach(Uuid::v7(), null);
    }

    private function download(Uuid $documentId): \App\Documents\Application\DocumentContents
    {
        $useCase = new DownloadDocument($this->documents, $this->storage, $this->auditLogger);

        return $useCase($documentId);
    }

    private function delete(Uuid $documentId): void
    {
        $useCase = new DeleteDocument(
            $this->documents,
            $this->auditLogger,
            new Clock(new MockClock($this->now)),
        );

        $useCase($documentId);
    }

    private function attach(Uuid $documentId, ?Uuid $serviceId): void
    {
        $useCase = new AttachDocumentToService($this->documents, $this->services);

        $useCase($documentId, $serviceId);
    }

    private function storedDocument(string $contents): Document
    {
        $document = $this->document();
        $this->storage->write($document->getStorageKey(), $contents);
        $this->documents->save($document);

        return $document;
    }

    private function document(): Document
    {
        return new Document(
            organizationId: $this->organizationId,
            originalFilename: 'factura.pdf',
            storageDriver: 'memory',
            storageKey: 'org/ab/cd/hash.pdf',
            mimeType: 'application/pdf',
            sizeBytes: 10,
            checksumSha256: str_repeat('a', 64),
            type: DocumentType::INVOICE,
            source: DocumentSource::MANUAL_UPLOAD,
        );
    }

    private function service(): Service
    {
        return new Service(
            organizationId: $this->organizationId,
            name: 'OVH VPS',
            currency: Currency::EUR,
            billingPeriod: BillingPeriod::MONTHLY,
            source: ServiceSource::MANUAL,
        );
    }
}
