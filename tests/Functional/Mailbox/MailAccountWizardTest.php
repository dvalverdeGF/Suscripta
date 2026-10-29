<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mailbox;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El asistente de alta de un buzón, paso a paso (D-93).
 *
 * El alta son dos pantallas: la primera elige el proveedor y la segunda pide
 * solo lo que ese proveedor necesita. Lo que se comprueba aquí es la interfaz
 * en sí —qué acción ofrece cada tipo, qué campos aparecen y a dónde apunta el
 * enlace de la contraseña de aplicación—, que es justo lo que no se veía.
 *
 * Cada prueba arranca su propio cliente en vez de hacerlo en `setUp()`: la de
 * OAuth necesita dar de alta las credenciales del proveedor **antes** de que se
 * instancie el servicio, porque `%env()%` se resuelve en ese momento.
 */
final class MailAccountWizardTest extends WebTestCase
{
    private KernelBrowser $client;

    /** @var array<string, array{env: ?string, server: ?string}> */
    private array $previousEnv = [];

    /**
     * @return iterable<string, array{EmailAccountProvider, string}>
     */
    public static function appPasswordProviders(): iterable
    {
        yield 'Gmail' => [EmailAccountProvider::GMAIL, 'https://myaccount.google.com/apppasswords'];
        yield 'Yahoo' => [EmailAccountProvider::YAHOO, 'https://login.yahoo.com/account/security'];
        yield 'iCloud' => [EmailAccountProvider::ICLOUD, 'https://account.apple.com/account/manage'];
    }

    public function testTheFirstStepOffersEveryProvider(): void
    {
        $this->open('/mail/accounts/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Conectar un buzón');

        foreach (['imap', 'gmail', 'microsoft', 'yahoo', 'icloud'] as $provider) {
            self::assertSelectorExists(
                'a[href="/mail/accounts/new/'.$provider.'"]',
                sprintf('Falta el proveedor «%s» en el primer paso.', $provider),
            );
        }
    }

    public function testTheFirstStepDoesNotAskForCredentials(): void
    {
        $this->open('/mail/accounts/new');

        self::assertSelectorNotExists('input[name="email_account_form[password]"]', 'El primer paso solo elige el proveedor.');
    }

    public function testAnUnknownProviderIsNotFound(): void
    {
        $this->open('/mail/accounts/new/aol');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheGenericImapStepAsksForTheServer(): void
    {
        $this->open('/mail/accounts/new/imap');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="email_account_form[imapHost]"]');
        self::assertSelectorExists('input[name="email_account_form[imapPort]"]');
        self::assertSelectorExists('select[name="email_account_form[imapEncryption]"]');
        self::assertSelectorExists('button[name="email_account_form[discover]"]');
        self::assertSelectorNotExists('select[name="email_account_form[provider]"]', 'El proveedor lo fija la ruta, no el formulario.');
        self::assertSelectorExists('p[data-mail-provider-target="appPasswordNote"][hidden]', 'Con IMAP genérico no hay contraseña de aplicación que generar.');
    }

    #[DataProvider('appPasswordProviders')]
    public function testAPresetProviderLinksToItsAppPasswordPage(EmailAccountProvider $provider, string $url): void
    {
        $this->open('/mail/accounts/new/'.$provider->value);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="email_account_form[imapHost]"]', 'El servidor lo pone el catálogo.');
        self::assertSelectorExists('input[name="email_account_form[password]"]');

        // El enlace estaba roto: el objetivo de Stimulus vivía en el párrafo y
        // el `href` del ancla se quedaba vacío, así que al pulsarlo se recargaba
        // la página. Ahora el `href` se pinta en el servidor.
        $link = $this->client->getCrawler()->filter('a[data-mail-provider-target="appPasswordLink"]');

        self::assertCount(1, $link, 'Falta el enlace a la página de la contraseña de aplicación.');
        self::assertSame($url, $link->attr('href'));
    }

    public function testTheMicrosoftStepExplainsWhyThereIsNoPasswordForm(): void
    {
        $this->open('/mail/accounts/new/microsoft');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="email_account_form[password]"]', 'Microsoft ya no acepta contraseñas.');
        self::assertSelectorTextContains('.bo-main', 'ha retirado la autenticación con contraseña');
    }

    public function testTheGoogleButtonIsInvisibleWithoutCredentials(): void
    {
        $this->open('/mail/accounts/new/gmail');

        self::assertSelectorNotExists('a[href="/mail/accounts/connect/google"]');
        self::assertSelectorTextContains('.bo-main', 'no está configurado en esta instalación');
    }

    public function testTheGoogleButtonAppearsWhenTheProviderIsConfigured(): void
    {
        // El alta del proveedor se hace por entorno y se resuelve al instanciar
        // el servicio, así que tiene que estar puesta antes de arrancar.
        $this->configure(['MAIL_OAUTH_GOOGLE_CLIENT_ID' => 'cliente-de-prueba', 'MAIL_OAUTH_GOOGLE_CLIENT_SECRET' => 'secreto-de-prueba']);

        $this->open('/mail/accounts/new/gmail');

        self::assertSelectorExists('a[href="/mail/accounts/connect/google"]');
        self::assertSelectorTextContains('.bo-main', 'Continuar con Google');
        // Gmail sigue aceptando contraseña de aplicación: se ofrece como alternativa.
        self::assertSelectorExists('input[name="email_account_form[password]"]');
    }

    public function testTheFirstStepRecommendsTheProvidersWithSingleSignOn(): void
    {
        $this->configure(['MAIL_OAUTH_MICROSOFT_CLIENT_ID' => 'cliente-de-prueba', 'MAIL_OAUTH_MICROSOFT_CLIENT_SECRET' => 'secreto-de-prueba']);

        $this->open('/mail/accounts/new');

        self::assertSelectorTextContains('.bo-main', 'Recomendado');
    }

    public function testTheMicrosoftStepOffersTheButtonWhenTheProviderIsConfigured(): void
    {
        $this->configure(['MAIL_OAUTH_MICROSOFT_CLIENT_ID' => 'cliente-de-prueba', 'MAIL_OAUTH_MICROSOFT_CLIENT_SECRET' => 'secreto-de-prueba']);

        $this->open('/mail/accounts/new/microsoft');

        self::assertSelectorExists('a[href="/mail/accounts/connect/microsoft"]');
        self::assertSelectorNotExists('input[name="email_account_form[password]"]');
    }

    public function testEditingAMailboxStillOffersTheProviderSelect(): void
    {
        $this->open('/mail/accounts/new/imap');
        $this->client->submitForm('Conectar buzón', [
            'email_account_form[emailAddress]' => 'facturas@ejemplo.com',
            'email_account_form[imapHost]' => 'imap.ejemplo.com',
            'email_account_form[imapPort]' => '993',
            'email_account_form[imapEncryption]' => ImapEncryption::SSL->value,
            'email_account_form[password]' => 'contraseña-de-aplicación',
            'email_account_form[imapFolder]' => 'INBOX',
        ]);

        self::assertResponseRedirects('/mail/accounts');

        $accounts = self::getContainer()->get(EmailAccountRepositoryInterface::class);
        self::assertInstanceOf(EmailAccountRepositoryInterface::class, $accounts);

        $account = $accounts->findByAddress('facturas@ejemplo.com');
        self::assertNotNull($account);

        $this->client->request('GET', '/mail/accounts/'.$account->getId()->toRfc4122().'/edit');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="email_account_form[provider]"]');
        self::assertSelectorExists('input[name="email_account_form[imapHost]"]');
    }

    /**
     * Arranca el cliente, registra a la usuaria y pide la pantalla.
     */
    private function open(string $url): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        /** @var User $user */
        $user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $this->client->loginUser($user);

        $this->client->request('GET', $url);
    }

    /**
     * Da de alta las credenciales de un proveedor de OAuth2 para esta prueba.
     *
     * Se escriben en `$_ENV` y `$_SERVER` porque es de donde lee el procesador
     * de variables de entorno de Symfony. `Dotenv` no pisa lo que ya existe, así
     * que el valor sobrevive al arranque del contenedor.
     *
     * Se guarda el valor anterior para devolverlo al terminar: borrarlas dejaría
     * al resto de la suite sin la variable —vacía en `.env`, pero declarada— y
     * cualquier pantalla que instancie el registro de proveedores daría un 500.
     *
     * @param array<string, string> $variables
     */
    private function configure(array $variables): void
    {
        foreach ($variables as $name => $value) {
            $this->previousEnv[$name] = [
                'env' => $_ENV[$name] ?? null,
                'server' => $_SERVER[$name] ?? null,
            ];

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $name => $previous) {
            $this->restore($_ENV, $name, $previous['env']);
            $this->restore($_SERVER, $name, $previous['server']);
        }

        $this->previousEnv = [];

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $target
     */
    private function restore(array &$target, string $name, ?string $value): void
    {
        if (null === $value) {
            unset($target[$name]);

            return;
        }

        $target[$name] = $value;
    }
}
