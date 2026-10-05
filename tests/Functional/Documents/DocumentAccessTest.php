<?php

declare(strict_types=1);

namespace App\Tests\Functional\Documents;

use App\Documents\Domain\Entity\Document;
use App\Documents\Domain\Enum\DocumentType;
use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;

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
 * Control de acceso y aislamiento por organización de los documentos.
 *
 * Un documento es una factura: contiene importes, direcciones y datos fiscales.
 * El binario no se sirve nunca por URL directa, así que la descarga también
 * tiene que respetar el filtro de tenencia (SECURITY.md §1 y §5).
 */
final class DocumentAccessTest extends WebTestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

    public function testAnonymousVisitorIsRedirectedToLogin(): void
    {
        $client = self::createClient();

        foreach (['/documents', '/documents/new', '/invoices', '/invoices/new'] as $uri) {
            $client->request('GET', $uri);

            self::assertResponseRedirects();
            self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
        }
    }

    public function testAnonymousVisitorCannotDownloadADocument(): void
    {
        $client = self::createClient();
        $client->request('GET', '/documents/'.Uuid::v7()->toRfc4122().'/download');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    /**
     * El criterio de verificación de la Fase 1 aplicado a documentos: si se
     * desactiva el filtro `tenant`, este test debe fallar.
     */
    public function testDocumentOfAnotherOrganizationIsNotVisible(): void
    {
        $client = self::createClient();
        $client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $register = self::getContainer()->get(RegisterUser::class);

        $ada = $register('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $client->loginUser($ada);

        $document = $this->upload($client, 'factura-de-ada.pdf');
        $id = $document->getId()->toRfc4122();

        // Consume the flash message so it does not leak into the next user's page.
        $client->followRedirect();

        $grace = $register('grace@example.com', 'Sup3rSecret!2026', 'Grace Hopper');
        $client->loginUser($grace);

        $client->request('GET', '/documents');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state__title', 'Todavía no hay documentos');
        self::assertSelectorTextNotContains('.app-main', 'factura-de-ada.pdf');

        $client->request('GET', '/documents/'.$id);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/documents/'.$id.'/download');
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/documents/'.$id.'/delete', ['_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/documents/'.$id.'/attach', ['_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(404);

        // El documento de Ada sigue intacto y accesible para ella.
        $client->loginUser($ada);
        $client->request('GET', '/documents/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'factura-de-ada.pdf');
    }

    private function upload(KernelBrowser $client, string $filename): Document
    {
        $directory = sys_get_temp_dir().'/suscripta-uploads';

        if (!is_dir($directory)) {
            mkdir($directory, 0o770, true);
        }

        $path = sprintf('%s/%s', $directory, $filename);
        file_put_contents($path, self::PDF);

        $client->request('GET', '/documents/new');
        $client->submitForm('Guardar documento', [
            'document_form[file]' => $path,
            'document_form[type]' => DocumentType::INVOICE->value,
        ]);

        self::assertResponseRedirects();

        $location = (string) $client->getResponse()->headers->get('Location');
        $document = $this->repository()->find(Uuid::fromString(substr($location, strlen('/documents/'))));

        self::assertInstanceOf(Document::class, $document);

        return $document;
    }

    private function repository(): DocumentRepositoryInterface
    {
        return self::getContainer()->get(DocumentRepositoryInterface::class);
    }
}
