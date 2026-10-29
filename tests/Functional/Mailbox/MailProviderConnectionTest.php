<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mailbox;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Mailbox\Application\Imap\Discovery\SrvRecord;
use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Enum\ImapAuthentication;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Tests\Support\Imap\RecordingImapClient;
use App\Tests\Support\Mailbox\FakeImapProbe;
use App\Tests\Support\Mailbox\FakeSrvResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Conexión de un buzón por HTTP real con los proveedores de catálogo (D-93).
 *
 * Lo que se comprueba aquí es lo que solo se ve con el formulario de verdad
 * delante: que con Gmail, Yahoo o iCloud **no** hay que escribir el servidor,
 * que con el IMAP genérico sí, y que Microsoft rechaza la contraseña con un
 * mensaje que dice qué hacer en vez de un error de credenciales.
 */
final class MailProviderConnectionTest extends WebTestCase
{
    private KernelBrowser $client;
    private RecordingImapClient $imap;
    private FakeSrvResolver $srv;
    private FakeImapProbe $probe;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');
        $this->client->disableReboot();

        /** @var User $user */
        $user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $this->client->loginUser($user);

        $imap = self::getContainer()->get(RecordingImapClient::class);
        self::assertInstanceOf(RecordingImapClient::class, $imap);
        $this->imap = $imap;
        $this->imap->reset();

        $srv = self::getContainer()->get(FakeSrvResolver::class);
        self::assertInstanceOf(FakeSrvResolver::class, $srv);
        $this->srv = $srv;
        $this->srv->reset();

        $probe = self::getContainer()->get(FakeImapProbe::class);
        self::assertInstanceOf(FakeImapProbe::class, $probe);
        $this->probe = $probe;
        $this->probe->reset();
    }

    /**
     * @return iterable<string, array{EmailAccountProvider, string, int}>
     */
    public static function presetProviders(): iterable
    {
        yield 'Gmail' => [EmailAccountProvider::GMAIL, 'imap.gmail.com', 993];
        yield 'Yahoo' => [EmailAccountProvider::YAHOO, 'imap.mail.yahoo.com', 993];
        yield 'iCloud' => [EmailAccountProvider::ICLOUD, 'imap.mail.me.com', 993];
    }

    #[DataProvider('presetProviders')]
    public function testAPresetProviderNeedsNoServerFromTheUser(
        EmailAccountProvider $provider,
        string $host,
        int $port,
    ): void {
        $this->client->request('GET', '/mail/accounts/new/'.$provider->value);
        $this->client->submitForm('Conectar buzón', [
            'email_account_form[emailAddress]' => 'facturas@ejemplo.com',
            'email_account_form[password]' => 'contraseña-de-aplicación',
            'email_account_form[imapFolder]' => 'INBOX',
        ]);

        self::assertResponseRedirects('/mail/accounts');

        $config = $this->imap->testConnectionCalls[0] ?? null;
        self::assertNotNull($config, 'Se comprueba la conexión antes de guardar.');
        self::assertSame($host, $config->host);
        self::assertSame($port, $config->port);
        self::assertSame(ImapEncryption::SSL, $config->encryption);
        self::assertSame(ImapAuthentication::PASSWORD, $config->authentication);

        $account = $this->accounts()->findByAddress('facturas@ejemplo.com');
        self::assertNotNull($account);
        self::assertSame($provider, $account->getProvider());
    }

    public function testGenericImapStillNeedsAServer(): void
    {
        $this->client->request('GET', '/mail/accounts/new/imap');
        $this->client->submitForm('Conectar buzón', [
            'email_account_form[emailAddress]' => 'facturas@ejemplo.com',
            'email_account_form[password]' => 'contraseña-de-aplicación',
            'email_account_form[imapFolder]' => 'INBOX',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.bo-main', 'Indica el servidor IMAP o usa «Detectar servidor».');
        self::assertNull($this->accounts()->findByAddress('facturas@ejemplo.com'));
    }

    public function testMicrosoftRefusesACraftedPassword(): void
    {
        // La pantalla no ofrece el formulario, pero el rechazo no puede depender
        // de que la pantalla lo esconda: se comprueba también en el servidor.
        // El testigo CSRF es de la sesión y del nombre del formulario, así que
        // vale el que pinta cualquier otro paso del asistente.
        $this->client->request('GET', '/mail/accounts/new/imap');

        $token = $this->client->getCrawler()
            ->filter('input[name="email_account_form[_token]"]')
            ->attr('value');

        $this->client->request('POST', '/mail/accounts/new/microsoft', [
            'email_account_form' => [
                '_token' => $token,
                'emailAddress' => 'facturas@outlook.com',
                'password' => 'contraseña-de-aplicación',
                'imapFolder' => 'INBOX',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.bo-main', 'ya no acepta contraseñas');
        self::assertCount(0, $this->imap->testConnectionCalls, 'Se rechaza antes de tocar la red.');
        self::assertNull($this->accounts()->findByAddress('facturas@outlook.com'));
    }

    public function testDetectingTheServerFillsTheHostWithoutSavingAnything(): void
    {
        $this->srv->records['_imaps._tcp.ejemplo.com'] = new SrvRecord('correo.ejemplo.com.', 993);

        $this->client->request('GET', '/mail/accounts/new/imap');
        $this->client->submitForm('Detectar servidor', [
            'email_account_form[emailAddress]' => 'facturas@ejemplo.com',
            'email_account_form[imapFolder]' => 'INBOX',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bo-main', 'correo.ejemplo.com');
        self::assertCount(0, $this->imap->testConnectionCalls, 'Detectar no abre la conexión: solo rellena el formulario.');
        self::assertNull($this->accounts()->findByAddress('facturas@ejemplo.com'));
    }

    public function testDetectingWithoutAnAddressSaysWhatIsMissing(): void
    {
        $this->client->request('GET', '/mail/accounts/new/imap');
        $this->client->submitForm('Detectar servidor', [
            'email_account_form[imapFolder]' => 'INBOX',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bo-main', 'Escribe primero la dirección del buzón');
    }

    public function testACancelledAuthorizationConnectsNothing(): void
    {
        $this->client->request('GET', '/mail/accounts/callback/google?error=access_denied');

        self::assertResponseRedirects('/mail/accounts/new/gmail');

        $this->client->followRedirect();

        self::assertSelectorTextContains('.bo-main', 'Has cancelado la autorización');
    }

    public function testAnIncompleteCallbackSaysSoInsteadOfFailing(): void
    {
        $this->client->request('GET', '/mail/accounts/callback/google?code=solo-el-codigo');

        self::assertResponseRedirects('/mail/accounts/new/gmail');

        $this->client->followRedirect();

        self::assertSelectorTextContains('.bo-main', 'La autorización ha llegado incompleta');
    }

    public function testAnUnknownProviderIsA404(): void
    {
        $this->client->request('GET', '/mail/accounts/connect/yahoo');

        self::assertResponseStatusCodeSame(404);
    }

    private function accounts(): EmailAccountRepositoryInterface
    {
        return self::getContainer()->get(EmailAccountRepositoryInterface::class);
    }
}
