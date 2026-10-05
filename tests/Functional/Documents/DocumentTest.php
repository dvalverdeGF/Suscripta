<?php

declare(strict_types=1);

namespace App\Tests\Functional\Documents;

use App\Documents\Domain\Entity\Document;
use App\Documents\Domain\Enum\DocumentSource;
use App\Documents\Domain\Enum\DocumentType;
use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Tests\Support\Services\ServiceEventAssertions;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function sprintf;
use function strlen;
use function substr;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

use function sys_get_temp_dir;

/**
 * Recorrido funcional de los documentos.
 *
 * Se prueba por HTTP real porque lo que hay que garantizar no es solo que el
 * caso de uso funcione, sino que **el binario no se sirve por URL directa** y
 * que la descarga pasa por un controlador que comprueba permisos y audita
 * (SECURITY.md §1).
 */
final class DocumentTest extends WebTestCase
{
    use ServiceEventAssertions;

    /** Un PDF mínimo pero real: el tipo se deduce del contenido, no del nombre. */
    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
        ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
        ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n"
        ."trailer<</Root 1 0 R>>\n%%EOF\n";

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $this->client->loginUser($this->user);
    }

    public function testIndexIsReachableAndEmpty(): void
    {
        $this->client->request('GET', '/documents');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Documentos');
        self::assertSelectorTextContains('.empty-state__title', 'Todavía no hay documentos');
    }

    public function testUploadAFileFromTheForm(): void
    {
        $this->client->request('GET', '/documents/new');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Guardar documento', [
            'document_form[file]' => $this->pdf('factura-ovh.pdf'),
            'document_form[type]' => DocumentType::INVOICE->value,
        ]);

        self::assertResponseRedirects();

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'factura-ovh.pdf');
        self::assertSelectorTextContains('.alert--success', 'se ha guardado');

        $document = $this->onlyDocument();

        self::assertSame('factura-ovh.pdf', $document->getOriginalFilename());
        self::assertSame('application/pdf', $document->getMimeType());
        self::assertSame(DocumentType::INVOICE, $document->getType());
        self::assertSame(DocumentSource::MANUAL_UPLOAD, $document->getSource());
        self::assertSame(strlen(self::PDF), $document->getSizeBytes());
    }

    public function testUploadingTheSameFileTwiceDoesNotDuplicateIt(): void
    {
        $this->upload('factura-ovh.pdf');
        $this->upload('copia-de-la-factura.pdf');

        self::assertSame(1, $this->repository()->countForOrganization());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'ya estaba en tus documentos');
    }

    public function testUploadRejectsAFileWithoutSelection(): void
    {
        $this->client->request('GET', '/documents/new');
        $this->client->submitForm('Guardar documento', [
            'document_form[type]' => DocumentType::INVOICE->value,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__error', 'Selecciona un fichero');
    }

    public function testUploadRejectsADisallowedMimeType(): void
    {
        $this->client->request('GET', '/documents/new');
        $this->client->submitForm('Guardar documento', [
            'document_form[file]' => $this->file('malware.exe', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00"),
            'document_form[type]' => DocumentType::OTHER->value,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert--danger', 'No aceptamos ficheros de tipo');
        self::assertSame(0, $this->repository()->countForOrganization());
    }

    public function testUploadAttachesTheDocumentToAService(): void
    {
        $service = $this->createService('OVH VPS');

        $this->client->request('GET', '/documents/new');
        $this->client->submitForm('Guardar documento', [
            'document_form[file]' => $this->pdf('factura-ovh.pdf'),
            'document_form[type]' => DocumentType::INVOICE->value,
            'document_form[serviceId]' => $service->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects();

        $document = $this->onlyDocument();

        self::assertSame($service->getId()->toRfc4122(), $document->getServiceId()?->toRfc4122());

        $reloaded = $this->reloadService($service);
        $events = $reloaded->getEvents();

        self::assertCount(2, $events, 'Alta del servicio y documento añadido.');
        self::assertSame(ServiceEventType::CREATED, self::eventAt($reloaded, 0)->getType());
        self::assertSame(ServiceEventType::DOCUMENT_ADDED, self::eventAt($reloaded, 1)->getType());
    }

    public function testDownloadReturnsTheStoredBinary(): void
    {
        $document = $this->upload('factura-ovh.pdf');

        $this->client->request('GET', '/documents/'.$document->getId()->toRfc4122().'/download');

        self::assertResponseIsSuccessful();
        self::assertSame(self::PDF, $this->client->getResponse()->getContent());
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('factura-ovh.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testDownloadOfAnUnknownDocumentIsNotFound(): void
    {
        $this->client->request('GET', '/documents/'.Uuid::v7()->toRfc4122().'/download');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAttachAndDetachFromTheShowPage(): void
    {
        $service = $this->createService('OVH VPS');
        $document = $this->upload('factura-ovh.pdf');
        $id = $document->getId()->toRfc4122();

        $this->client->request('GET', '/documents/'.$id);
        $this->client->submitForm('Guardar asignación', [
            'serviceId' => $service->getId()->toRfc4122(),
        ]);

        self::assertResponseRedirects('/documents/'.$id);
        self::assertSame($service->getId()->toRfc4122(), $this->reload($document)->getServiceId()?->toRfc4122());

        $this->client->request('GET', '/documents/'.$id);
        $this->client->submitForm('Guardar asignación', ['serviceId' => '']);

        self::assertNull($this->reload($document)->getServiceId());
    }

    public function testDeleteRemovesTheDocumentFromTheList(): void
    {
        $document = $this->upload('factura-ovh.pdf');
        $id = $document->getId()->toRfc4122();

        $this->client->request('GET', '/documents/'.$id);
        $this->client->submitForm('Eliminar documento');

        self::assertResponseRedirects('/documents');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'se ha eliminado');
        self::assertSelectorTextContains('.empty-state__title', 'Todavía no hay documentos');

        self::assertTrue($this->reload($document)->isDeleted());
    }

    public function testADeletedDocumentCannotBeDownloaded(): void
    {
        $document = $this->upload('factura-ovh.pdf');
        $id = $document->getId()->toRfc4122();

        $this->client->request('GET', '/documents/'.$id);
        $this->client->submitForm('Eliminar documento');

        $this->client->request('GET', '/documents/'.$id.'/download');

        self::assertResponseStatusCodeSame(404);
    }

    public function testIndexFiltersByType(): void
    {
        $this->upload('factura.pdf', DocumentType::INVOICE);
        // Contenido distinto: si fuese idéntico, el checksum lo deduplicaría.
        $this->upload('contrato.pdf', DocumentType::CONTRACT, self::PDF."% contrato\n");

        $this->client->request('GET', '/documents?type=contract');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'contrato.pdf');
        self::assertSelectorTextNotContains('table', 'factura.pdf');
    }

    private function upload(string $filename, DocumentType $type = DocumentType::INVOICE, ?string $contents = null): Document
    {
        $this->client->request('GET', '/documents/new');
        $this->client->submitForm('Guardar documento', [
            'document_form[file]' => $this->file($filename, $contents ?? self::PDF),
            'document_form[type]' => $type->value,
        ]);

        self::assertResponseRedirects();

        $location = (string) $this->client->getResponse()->headers->get('Location');
        $id = Uuid::fromString(substr($location, strlen('/documents/')));

        $document = $this->repository()->find($id);

        self::assertInstanceOf(Document::class, $document);

        return $document;
    }

    private function pdf(string $filename): string
    {
        return $this->file($filename, self::PDF);
    }

    /**
     * `submitForm()` recibe la **ruta** del fichero, no un `UploadedFile`: el
     * campo de formulario de DomCrawler copia el fichero a un temporal y toma
     * el nombre del `basename`. Por eso el fichero se crea ya con el nombre
     * definitivo dentro de un directorio propio.
     */
    private function file(string $filename, string $contents): string
    {
        $directory = sys_get_temp_dir().'/suscripta-uploads';

        if (!is_dir($directory)) {
            mkdir($directory, 0o770, true);
        }

        $path = sprintf('%s/%s', $directory, $filename);
        file_put_contents($path, $contents);

        return $path;
    }

    private function onlyDocument(): Document
    {
        $documents = $this->repository()->findForOrganization();

        self::assertCount(1, $documents, 'Se esperaba exactamente un documento.');

        return $documents[0];
    }

    private function reload(Document $document): Document
    {
        $reloaded = $this->repository()->find($document->getId());

        self::assertInstanceOf(Document::class, $reloaded);

        return $reloaded;
    }

    private function createService(string $name): Service
    {
        $this->client->request('GET', '/services/new');
        $this->client->submitForm('Añadir servicio', [
            'service_form[name]' => $name,
            'service_form[amount]' => '11,99',
            'service_form[currency]' => 'EUR',
            'service_form[billingPeriod]' => BillingPeriod::MONTHLY->value,
            'service_form[billingIntervalCount]' => '1',
            'service_form[autoRenews]' => '1',
        ]);

        self::assertResponseRedirects();

        foreach ($this->serviceRepository()->findForOrganization(ServiceFilters::none()) as $service) {
            if ($service->getName() === $name) {
                return $service;
            }
        }

        self::fail(sprintf('No se ha creado el servicio «%s».', $name));
    }

    private function reloadService(Service $service): Service
    {
        $reloaded = $this->serviceRepository()->find($service->getId());

        self::assertInstanceOf(Service::class, $reloaded);

        return $reloaded;
    }

    private function repository(): DocumentRepositoryInterface
    {
        return self::getContainer()->get(DocumentRepositoryInterface::class);
    }

    private function serviceRepository(): ServiceRepositoryInterface
    {
        return self::getContainer()->get(ServiceRepositoryInterface::class);
    }
}
