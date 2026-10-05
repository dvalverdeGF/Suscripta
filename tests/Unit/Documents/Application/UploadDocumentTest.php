<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documents\Application;

use App\Documents\Application\Dto\DocumentUpload;
use App\Documents\Application\UploadDocument;
use App\Documents\Domain\Enum\DocumentSource;
use App\Documents\Domain\Enum\DocumentType;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Enum\ServiceSource;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Tests\Support\Documents\InMemoryDocumentRepository;
use App\Tests\Support\Documents\InMemoryDocumentStorage;
use App\Tests\Support\Services\ServiceEventAssertions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function str_ends_with;
use function str_repeat;

use Symfony\Component\Uid\Uuid;

/**
 * Subir un documento tiene dos propiedades que sostienen el producto:
 *
 * 1. **Idempotencia por huella.** La misma factura que llega por dos buzones es
 *    un solo documento (D-27). Si esto fallara, el panel contaría el gasto dos
 *    veces.
 * 2. **Lista blanca de tipos.** Un fichero que no reconocemos se rechaza; no se
 *    guarda "por si acaso" en un almacenamiento que luego hay que servir.
 */
final class UploadDocumentTest extends TestCase
{
    use ServiceEventAssertions;

    private Uuid $organizationId;
    private InMemoryDocumentRepository $documents;
    private InMemoryDocumentStorage $storage;
    private ServiceRepositoryInterface&MockObject $services;
    private AuditLoggerInterface&MockObject $auditLogger;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->documents = new InMemoryDocumentRepository();
        $this->storage = new InMemoryDocumentStorage();
        $this->services = $this->createMock(ServiceRepositoryInterface::class);
        $this->auditLogger = $this->createMock(AuditLoggerInterface::class);
        $this->tenantContext = new TenantContext();
        $this->tenantContext->setOrganizationId($this->organizationId);
    }

    public function testItStoresTheFileAndPersistsTheDocument(): void
    {
        $result = $this->upload();

        self::assertTrue($result['created']);
        self::assertSame('factura.pdf', $result['document']->getOriginalFilename());
        self::assertSame('application/pdf', $result['document']->getMimeType());
        self::assertSame(1, $this->documents->countForOrganization());
        self::assertCount(1, $this->storage->written);
    }

    public function testItWritesTheContentsBeforePersisting(): void
    {
        $result = $this->upload(contents: 'contenido real');

        self::assertSame('contenido real', $this->storage->read($result['document']->getStorageKey()));
    }

    public function testItRecordsTheSizeAndTheChecksum(): void
    {
        $result = $this->upload(contents: 'abc');

        self::assertSame(3, $result['document']->getSizeBytes());
        self::assertSame(
            'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad',
            $result['document']->getChecksumSha256(),
        );
    }

    public function testItShardsTheStorageKeyByTheChecksum(): void
    {
        $result = $this->upload(contents: 'abc');
        $key = $result['document']->getStorageKey();

        self::assertStringStartsWith($this->organizationId->toRfc4122().'/ba/78/', $key);
        self::assertStringEndsWith('.pdf', $key);
    }

    public function testItKeepsTheExtensionOfTheOriginalFilename(): void
    {
        $result = $this->upload(filename: 'captura.PNG', mimeType: 'image/png');

        self::assertStringEndsWith('.png', $result['document']->getStorageKey());
    }

    public function testItDropsAnUnsafeExtension(): void
    {
        $result = $this->upload(filename: 'factura.pdf/../../evil', mimeType: 'application/pdf');
        $key = $result['document']->getStorageKey();

        // La extensión se descarta entera: el nombre viene de fuera y no puede
        // acabar formando parte de una ruta.
        self::assertStringNotContainsString('..', $key);
        self::assertStringNotContainsString('evil', $key);
        self::assertTrue(str_ends_with($key, (string) $result['document']->getChecksumSha256()));
    }

    public function testItDropsAnOverlongExtension(): void
    {
        $result = $this->upload(filename: 'factura.extensionlarguisima', mimeType: 'application/pdf');

        self::assertStringNotContainsString('extensionlarguisima', $result['document']->getStorageKey());
    }

    public function testUploadingTheSameFileTwiceDoesNotDuplicateIt(): void
    {
        $first = $this->upload(contents: 'la misma factura');
        $second = $this->upload(contents: 'la misma factura');

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame(
            $first['document']->getId()->toRfc4122(),
            $second['document']->getId()->toRfc4122(),
        );
        self::assertSame(1, $this->documents->countForOrganization());
        self::assertCount(1, $this->storage->written);
    }

    public function testItRejectsAnEmptyFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->upload(contents: '');
    }

    public function testItRejectsAFileWithoutAName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->upload(filename: '');
    }

    public function testItRejectsADisallowedMimeType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->upload(mimeType: 'application/x-msdownload');
    }

    public function testItAcceptsTheMimeTypeCaseInsensitively(): void
    {
        $result = $this->upload(mimeType: 'APPLICATION/PDF');

        self::assertSame('application/pdf', $result['document']->getMimeType());
    }

    public function testItRejectsAFileOverTheSizeLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->upload(contents: str_repeat('a', 20 * 1024 * 1024 + 1));
    }

    public function testItAcceptsAFileExactlyAtTheSizeLimit(): void
    {
        $result = $this->upload(contents: str_repeat('a', 20 * 1024 * 1024));

        self::assertSame(20 * 1024 * 1024, $result['document']->getSizeBytes());
    }

    public function testItAttachesTheDocumentToAServiceAndRecordsTheEvent(): void
    {
        $service = $this->service();
        $this->services->method('find')->willReturn($service);

        $result = $this->upload(serviceId: $service->getId());

        self::assertSame($service->getId()->toRfc4122(), $result['document']->getServiceId()?->toRfc4122());
        self::assertCount(1, $service->getEvents());
        self::assertSame(ServiceEventType::DOCUMENT_ADDED, self::eventAt($service, 0)->getType());
    }

    public function testItDoesNotRecordAnEventWhenTheServiceIsUnknown(): void
    {
        $this->services->method('find')->willReturn(null);

        $result = $this->upload(serviceId: Uuid::v7());

        self::assertTrue($result['created']);
    }

    public function testItLinksTheDocumentToTheEmailMessageItCameFrom(): void
    {
        $messageId = Uuid::v7();

        $result = $this->upload(emailMessageId: $messageId, source: DocumentSource::EMAIL_ATTACHMENT);

        self::assertSame($messageId->toRfc4122(), $result['document']->getEmailMessageId()?->toRfc4122());
        self::assertSame(DocumentSource::EMAIL_ATTACHMENT, $result['document']->getSource());
    }

    public function testItAuditsTheUpload(): void
    {
        $this->auditLogger
            ->expects(self::once())
            ->method('log')
            ->with(
                self::equalTo(AuditAction::DOCUMENT_UPLOADED),
                self::equalTo('document'),
                self::isType('string'),
                self::isType('array'),
                self::anything(),
            );

        $this->upload();
    }

    public function testItRefusesToWorkWithoutAnActiveOrganization(): void
    {
        $this->tenantContext->setOrganizationId(null);

        $this->expectException(InvalidArgumentException::class);

        $this->upload();
    }

    /**
     * @return array{document: \App\Documents\Domain\Entity\Document, created: bool}
     */
    private function upload(
        string $contents = 'contenido',
        string $filename = 'factura.pdf',
        string $mimeType = 'application/pdf',
        DocumentType $type = DocumentType::INVOICE,
        DocumentSource $source = DocumentSource::MANUAL_UPLOAD,
        ?Uuid $serviceId = null,
        ?Uuid $emailMessageId = null,
    ): array {
        $useCase = new UploadDocument(
            $this->documents,
            $this->storage,
            $this->services,
            $this->tenantContext,
            $this->auditLogger,
        );

        return $useCase(new DocumentUpload(
            contents: $contents,
            originalFilename: $filename,
            mimeType: $mimeType,
            type: $type,
            source: $source,
            serviceId: $serviceId,
            emailMessageId: $emailMessageId,
        ));
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
