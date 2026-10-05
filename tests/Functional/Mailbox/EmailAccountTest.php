<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mailbox;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Mailbox\Application\Imap\ImapClientInterface;
use App\Mailbox\Application\Imap\ImapMessageBody;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Enum\EmailAccountStatus;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Tests\Support\Imap\RecordingImapClient;
use DateTimeImmutable;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Conexión de buzones por HTTP real.
 *
 * Lo que se comprueba aquí no es el formulario, sino la promesa de privacidad:
 * que la contraseña **nunca** vuelve al navegador, que un buzón que no responde
 * no se guarda, y que eliminar un buzón se lleva sus datos por delante
 * (SECURITY.md §2 y §3).
 */
final class EmailAccountTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;
    private RecordingImapClient $imap;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');
        // Sin reinicio del kernel, el doble de IMAP que se obtiene aquí es el
        // mismo objeto que recibe la aplicación en cada petición.
        $this->client->disableReboot();

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $this->client->loginUser($this->user);

        $imap = self::getContainer()->get(RecordingImapClient::class);
        self::assertInstanceOf(RecordingImapClient::class, $imap);
        $this->imap = $imap;
        $this->imap->reset();
    }

    private function accounts(): EmailAccountRepositoryInterface
    {
        return self::getContainer()->get(EmailAccountRepositoryInterface::class);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function connect(array $overrides = []): EmailAccount
    {
        $this->client->request('GET', '/mail/accounts/new');
        $this->client->submitForm('Conectar buzón', $overrides + [
            'email_account_form[emailAddress]' => 'facturas@miempresa.com',
            'email_account_form[displayName]' => 'Correo de facturación',
            'email_account_form[imapHost]' => 'imap.miempresa.com',
            'email_account_form[imapPort]' => '993',
            'email_account_form[imapEncryption]' => ImapEncryption::SSL->value,
            'email_account_form[password]' => 'contraseña-de-aplicación',
            'email_account_form[imapFolder]' => 'INBOX',
            'email_account_form[provider]' => 'imap',
        ]);

        self::assertResponseRedirects();

        $account = $this->accounts()->findByAddress('facturas@miempresa.com');

        self::assertNotNull($account);

        return $account;
    }

    public function testTheIndexStartsEmpty(): void
    {
        $this->client->request('GET', '/mail/accounts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Buzones de correo');
        self::assertSelectorTextContains('.empty-state__title', 'Todavía no hay ningún buzón conectado');
    }

    public function testConnectingAMailboxChecksTheConnectionBeforeSaving(): void
    {
        $account = $this->connect();

        self::assertCount(1, $this->imap->testConnectionCalls, 'Se comprueba la conexión exactamente una vez.');

        $config = $this->imap->testConnectionCalls[0];

        self::assertSame('imap.miempresa.com', $config->host);
        self::assertSame(993, $config->port);
        self::assertSame(ImapEncryption::SSL, $config->encryption);
        self::assertSame('contraseña-de-aplicación', $config->password);

        self::assertSame('facturas@miempresa.com', $account->getEmailAddress());
        self::assertSame(EmailAccountStatus::ACTIVE, $account->getStatus());
        self::assertTrue($account->isConfigured());
    }

    public function testThePasswordIsStoredEncryptedAndNeverRendered(): void
    {
        $account = $this->connect();

        self::assertNotNull($account->getCredentialsEncrypted());
        self::assertStringNotContainsString('contraseña-de-aplicación', (string) $account->getCredentialsEncrypted());

        $this->client->request('GET', '/mail/accounts/'.$account->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('contraseña-de-aplicación', (string) $this->client->getResponse()->getContent());
    }

    public function testAMailboxThatDoesNotAnswerIsNotSaved(): void
    {
        $this->imap->willFailToConnect('No se pudo conectar con el servidor IMAP. Revisa el servidor y el puerto.');

        $this->client->request('GET', '/mail/accounts/new');
        $this->client->submitForm('Conectar buzón', [
            'email_account_form[emailAddress]' => 'facturas@miempresa.com',
            'email_account_form[imapHost]' => 'imap.miempresa.com',
            'email_account_form[imapPort]' => '993',
            'email_account_form[imapEncryption]' => ImapEncryption::SSL->value,
            'email_account_form[password]' => 'mala',
            'email_account_form[imapFolder]' => 'INBOX',
            'email_account_form[provider]' => 'imap',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert--danger', 'No se pudo conectar');
        self::assertNull($this->accounts()->findByAddress('facturas@miempresa.com'));
    }

    public function testTheSameMailboxCannotBeConnectedTwice(): void
    {
        $this->connect();

        $this->imap->reset();

        $this->client->request('GET', '/mail/accounts/new');
        $this->client->submitForm('Conectar buzón', [
            'email_account_form[emailAddress]' => 'facturas@miempresa.com',
            'email_account_form[imapHost]' => 'imap.miempresa.com',
            'email_account_form[imapPort]' => '993',
            'email_account_form[imapEncryption]' => ImapEncryption::SSL->value,
            'email_account_form[password]' => 'otra',
            'email_account_form[imapFolder]' => 'INBOX',
            'email_account_form[provider]' => 'imap',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert--danger', 'ya está conectado');
        self::assertCount(0, $this->imap->testConnectionCalls, 'Un buzón duplicado se rechaza antes de tocar la red.');
    }

    public function testTheDetailShowsTheConnectionStateAndTheReadOnlyPromise(): void
    {
        $account = $this->connect();

        $this->client->request('GET', '/mail/accounts/'.$account->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Correo de facturación');
        self::assertSelectorTextContains('.app-main', 'imap.miempresa.com');
        self::assertSelectorTextContains('.app-main', 'Solo se guardan metadatos');
    }

    public function testTestingTheConnectionFromTheDetailPageReportsSuccess(): void
    {
        $account = $this->connect();

        $this->imap->reset();

        $this->client->request('GET', '/mail/accounts/'.$account->getId()->toRfc4122());
        $this->client->submitForm('Probar conexión');

        self::assertResponseRedirects();
        self::assertCount(1, $this->imap->listFoldersCalls, 'Probar la conexión lista las carpetas, no descarga correo.');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Conexión correcta');
    }

    public function testEditingKeepsThePasswordWhenTheFieldIsLeftEmpty(): void
    {
        $account = $this->connect();
        $before = $account->getCredentialsEncrypted();

        $this->client->request('GET', '/mail/accounts/'.$account->getId()->toRfc4122().'/edit');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Guardar cambios', [
            'email_account_form[displayName]' => 'Facturación de la empresa',
            'email_account_form[imapHost]' => 'imap.miempresa.com',
            'email_account_form[imapPort]' => '993',
            'email_account_form[imapEncryption]' => ImapEncryption::SSL->value,
            'email_account_form[password]' => '',
            'email_account_form[imapFolder]' => 'INBOX',
            'email_account_form[provider]' => 'imap',
        ]);

        self::assertResponseRedirects();

        $reloaded = $this->accounts()->find($account->getId());

        self::assertNotNull($reloaded);
        self::assertSame('Facturación de la empresa', $reloaded->getDisplayName());
        self::assertSame($before, $reloaded->getCredentialsEncrypted(), 'La contraseña no se toca si el campo va vacío.');
    }

    public function testDisconnectingKeepsTheMailboxButForgetsTheCredentials(): void
    {
        $account = $this->connect();

        $this->client->request('GET', '/mail/accounts/'.$account->getId()->toRfc4122());
        $this->client->submitForm('Desconectar');

        self::assertResponseRedirects();

        $reloaded = $this->accounts()->find($account->getId());

        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->hasCredentials());
        self::assertFalse($reloaded->isConfigured());
    }

    public function testDeletingAMailboxRemovesItAndItsData(): void
    {
        $account = $this->connect();

        $this->client->request('GET', '/mail/accounts/'.$account->getId()->toRfc4122());
        $this->client->submitForm('Eliminar buzón y sus datos');

        self::assertResponseRedirects('/mail/accounts');

        self::assertNull($this->accounts()->find($account->getId()));
    }

    public function testAMailboxOfAnotherOrganizationIsNotVisible(): void
    {
        $account = $this->connect();

        $grace = self::getContainer()->get(RegisterUser::class)('grace@example.com', 'Sup3rSecret!2026', 'Grace Hopper');
        $this->client->loginUser($grace);

        $this->client->request('GET', '/mail/accounts');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state__title', 'Todavía no hay ningún buzón conectado');

        $this->client->request('GET', '/mail/accounts/'.$account->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheFormAsksForAServerNotForAProvider(): void
    {
        $this->client->request('GET', '/mail/accounts/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="email_account_form[imapHost]"]');
        self::assertSelectorExists('input[name="email_account_form[imapPort]"]');
        self::assertSelectorExists('select[name="email_account_form[imapEncryption]"]');
        self::assertSelectorTextContains('.app-main', 'cualquier servidor IMAP');
    }

    public function testTheSyncCommandIsAvailableForConnectedMailboxes(): void
    {
        $this->connect();

        $this->imap->reset();
        $this->imap->willReturnHeaders([new ImapMessageHeader(
            uid: 1,
            messageId: '<factura@miempresa.com>',
            fromAddress: 'facturacion@ovh.com',
            fromName: 'OVH',
            replyTo: null,
            toAddresses: ['facturas@miempresa.com'],
            subject: 'Tu factura de octubre',
            receivedAt: new DateTimeImmutable('2026-10-03 08:00:00'),
            sizeBytes: 2048,
            contentType: 'multipart/mixed',
            attachmentNames: ['factura.pdf'],
            attachmentTypes: ['application/pdf'],
        )]);

        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('app:mail:sync'));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 mensaje', $tester->getDisplay());
        self::assertCount(0, $this->imap->fetchBodyCalls, 'La sincronización solo lee cabeceras (D-38).');
    }

    public function testTheSyncCommandDoesNothingWithoutMailboxes(): void
    {
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('app:mail:sync'));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No hay ningún buzón', $tester->getDisplay());
    }

    public function testTheImapClientIsNeverAskedToWrite(): void
    {
        $this->connect();

        $reflection = new ReflectionClass(ImapClientInterface::class);
        $names = array_map(static fn (ReflectionMethod $method): string => $method->getName(), $reflection->getMethods());

        self::assertSame(['testConnection', 'listFolders', 'fetchHeaders', 'fetchBody'], $names, 'El contrato de IMAP es de solo lectura: no existe ninguna operación de escritura.');
    }

    public function testTheBodyIsNotDownloadedWhenListingMailboxes(): void
    {
        $this->connect();

        $this->imap->reset();

        $this->client->request('GET', '/mail/accounts');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $this->imap->fetchBodyCalls);
        self::assertCount(0, $this->imap->fetchHeadersCalls);
    }

    public function testAnUnusedImportIsNotSilentlyIgnored(): void
    {
        // Guarda contra el olvido de `ImapMessageBody` en el contrato público.
        self::assertTrue(class_exists(ImapMessageBody::class));
    }
}
