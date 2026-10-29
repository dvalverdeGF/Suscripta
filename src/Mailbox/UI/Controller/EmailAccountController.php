<?php

declare(strict_types=1);

namespace App\Mailbox\UI\Controller;

use App\Identity\Domain\Entity\User;
use App\Mailbox\Application\ConnectEmailAccount;
use App\Mailbox\Application\DeleteEmailAccount;
use App\Mailbox\Application\DisconnectEmailAccount;
use App\Mailbox\Application\Forwarding\DisableEmailForwarding;
use App\Mailbox\Application\Forwarding\EnableEmailForwarding;
use App\Mailbox\Application\Forwarding\RotateForwardingAddress;
use App\Mailbox\Application\Imap\Discovery\DiscoverImapSettings;
use App\Mailbox\Application\Oauth\CompleteMailOauth;
use App\Mailbox\Application\Oauth\MailOauthProviderRegistry;
use App\Mailbox\Application\Oauth\StartMailOauth;
use App\Mailbox\Application\SyncEmailAccount;
use App\Mailbox\Application\TestEmailAccountConnection;
use App\Mailbox\Application\UpdateEmailAccount;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Exception\ImapConnectionException;
use App\Mailbox\Domain\Exception\MailOauthException;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailSyncRunRepositoryInterface;
use App\Mailbox\UI\Form\EmailAccountFormData;
use App\Mailbox\UI\Form\EmailAccountFormType;
use App\Mailbox\UI\Form\ForwardingFormData;
use App\Mailbox\UI\Form\ForwardingFormType;
use App\Shared\Application\RateLimit\RateLimitGuard;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Exception\InvalidArgumentException;

use function array_filter;
use function array_values;
use function count;
use function is_string;
use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Conexión y gestión de los buzones de correo de la organización.
 *
 * Es la puerta de entrada del producto: sin un buzón conectado no hay nada que
 * descubrir. Por eso el formulario pide lo mínimo (servidor, usuario,
 * contraseña) y comprueba la conexión **antes** de guardar, para que el error
 * aparezca mientras el usuario todavía tiene la contraseña delante.
 */
#[Route('/mail/accounts', name: 'app_mail_accounts_')]
#[IsGranted('ROLE_USER')]
final class EmailAccountController extends AbstractController
{
    public function __construct(
        private readonly EmailAccountRepositoryInterface $accounts,
        private readonly EmailMessageRepositoryInterface $messages,
        private readonly EmailSyncRunRepositoryInterface $runs,
        private readonly RateLimitGuard $rateLimits,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $accounts = $this->accounts->findForOrganization();

        return $this->render('mail/accounts/index.html.twig', [
            'accounts' => $accounts,
            'messageCounts' => $this->messages->countByAccount(),
        ]);
    }

    /**
     * Primer paso del asistente: elegir el proveedor.
     *
     * No es un formulario sino un índice de caminos. El proveedor no es un
     * desplegable más dentro de un formulario largo porque **cambia lo que hay
     * que pedir**: con Gmail o Yahoo hace falta una contraseña de aplicación,
     * con Microsoft solo vale el consentimiento del proveedor, y con IMAP
     * genérico hay que buscar el servidor. Preguntarlo primero deja el segundo
     * paso con los campos justos.
     */
    #[Route('/new', name: 'new', methods: ['GET'])]
    public function new(MailOauthProviderRegistry $oauthProviders): Response
    {
        return $this->render('mail/accounts/new.html.twig', [
            'providers' => array_values(array_filter(
                EmailAccountProvider::cases(),
                static fn (EmailAccountProvider $provider): bool => $provider->isImplemented(),
            )),
            'oauth_providers' => $oauthProviders->configuredProviders(),
        ]);
    }

    /**
     * Segundo paso del asistente: conectar el proveedor elegido.
     *
     * El proveedor viene de la ruta y **no** del formulario: con él se decide
     * qué campos existen, así que aceptarlo del cliente sería dejar que el
     * formulario eligiera su propia validación. `requirements` cierra la ruta a
     * los valores del catálogo y el `EmailAccountProvider::from()` de abajo
     * convierte la cadena en el enum una sola vez.
     */
    #[Route(
        '/new/{provider}',
        name: 'create',
        methods: ['GET', 'POST'],
        requirements: ['provider' => 'imap|gmail|microsoft|yahoo|icloud'],
    )]
    public function create(
        string $provider,
        Request $request,
        ConnectEmailAccount $connectEmailAccount,
        DiscoverImapSettings $discoverImapSettings,
        MailOauthProviderRegistry $oauthProviders,
    ): Response {
        $target = EmailAccountProvider::from($provider);

        $data = new EmailAccountFormData();
        $data->provider = $target;

        $form = $this->createForm(EmailAccountFormType::class, $data, ['is_new' => true, 'provider' => $target]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $this->discoverClicked($form)) {
            return $this->discover($target, $data, $discoverImapSettings, $oauthProviders);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->rateLimits->acquire('mail_account', $this->organizationKey())) {
                $form->addError(new FormError('Has conectado demasiados buzones seguidos. Espera un minuto y vuelve a intentarlo.'));

                return $this->renderCreate($target, $form, $oauthProviders, Response::HTTP_TOO_MANY_REQUESTS);
            }

            try {
                $account = $connectEmailAccount($data->toInput(), $this->actorId());
            } catch (ImapConnectionException|InvalidArgumentException $exception) {
                // El error se cuelga del formulario y no de un mensaje flash:
                // así la respuesta es 422 y el usuario ve el motivo junto a los
                // campos que tiene que corregir, sin perder lo que escribió.
                $form->addError(new FormError($exception->getMessage()));
            }

            if (isset($account)) {
                $this->addFlash('success', sprintf('El buzón %s se ha conectado. Ya podemos empezar a buscar facturas.', $account->getEmailAddress()));

                return $this->redirectToRoute('app_mail_accounts_index');
            }
        }

        return $this->renderCreate($target, $form, $oauthProviders);
    }

    /**
     * Pinta el segundo paso. Lo usan la respuesta normal, el 422 y el 429, para
     * que el usuario nunca pierda el proveedor que había elegido.
     *
     * @param FormInterface<EmailAccountFormData> $form
     */
    private function renderCreate(
        EmailAccountProvider $provider,
        FormInterface $form,
        MailOauthProviderRegistry $oauthProviders,
        int $status = Response::HTTP_OK,
    ): Response {
        return $this->render('mail/accounts/create.html.twig', [
            'provider' => $provider,
            'form' => $form,
            'oauth_configured' => $oauthProviders->isConfigured($provider),
            'can_use_password' => $provider->acceptsPassword(),
            'app_password_url' => $provider->preset()?->appPasswordUrl,
        ], new Response(status: $status));
    }

    /**
     * ¿Se ha pulsado el botón de detección?
     *
     * `FormInterface::get()` devuelve la interfaz genérica, pero un
     * `SubmitType` siempre es un `ClickableInterface`: el `instanceof` es lo
     * que le dice al analizador estático que `isClicked()` existe de verdad.
     *
     * @param FormInterface<EmailAccountFormData> $form
     */
    private function discoverClicked(FormInterface $form): bool
    {
        // El botón solo existe con IMAP genérico: con un proveedor de catálogo
        // no hay nada que detectar.
        if (!$form->has('discover')) {
            return false;
        }

        $button = $form->get('discover');

        return $button instanceof ClickableInterface && $button->isClicked();
    }

    /**
     * Rellena el servidor IMAP a partir de la dirección y devuelve el formulario
     * limpio.
     *
     * No se reutiliza el formulario enviado a propósito: está «sometido» y
     * arrastraría los errores de la validación anterior («indica el servidor
     * IMAP») justo cuando acabamos de rellenarlo. Se devuelve uno nuevo con lo
     * que el usuario ya había escrito y el servidor detectado.
     */
    private function discover(
        EmailAccountProvider $provider,
        EmailAccountFormData $data,
        DiscoverImapSettings $discoverImapSettings,
        MailOauthProviderRegistry $oauthProviders,
    ): Response {
        if (!$this->rateLimits->acquire('mail_discovery', $this->organizationKey())) {
            return $this->tooManyRequests(
                'Has pedido demasiadas detecciones seguidas. Espera un minuto y vuelve a intentarlo.',
                'app_mail_accounts_create',
                ['provider' => $provider->value],
            );
        }

        $fresh = new EmailAccountFormData();
        $fresh->emailAddress = $data->emailAddress;
        $fresh->provider = $provider;
        $fresh->displayName = $data->displayName;
        $fresh->imapUsername = $data->imapUsername;
        $fresh->imapFolder = $data->imapFolder;

        try {
            $endpoint = $discoverImapSettings((string) $data->emailAddress);

            $fresh->imapHost = $endpoint->host;
            $fresh->imapPort = $endpoint->port;
            $fresh->imapEncryption = $endpoint->encryption;

            $this->addFlash('success', sprintf(
                'Hemos encontrado el servidor %s:%d (%s) por %s. Comprueba que es el tuyo y escribe la contraseña.',
                $endpoint->host,
                $endpoint->port,
                $endpoint->encryption->label(),
                $endpoint->source,
            ));
        } catch (ImapConnectionException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->renderCreate(
            $provider,
            $this->createForm(EmailAccountFormType::class, $fresh, ['is_new' => true, 'provider' => $provider]),
            $oauthProviders,
        );
    }

    /**
     * Primer paso del flujo OAuth2: mandar al usuario a autorizar en el proveedor.
     *
     * El `redirect_uri` se construye con la ruta real y no con una constante: en
     * local es `http://localhost:8000/...` y en producción la del sitio, y el
     * proveedor exige que coincida exactamente con la declarada.
     */
    #[Route('/connect/{provider}', name: 'connect', methods: ['GET'])]
    public function connect(string $provider, StartMailOauth $startOauth): Response
    {
        $target = $this->providerFromOauthKey($provider);

        try {
            $url = $startOauth($target, $this->oauthRedirectUri($target));
        } catch (MailOauthException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_mail_accounts_create', ['provider' => $target->value]);
        }

        return $this->redirect($url);
    }

    /**
     * Segundo paso del flujo OAuth2: el proveedor devuelve aquí al usuario.
     *
     * Los errores vuelven como `error` en la query (el usuario ha cancelado),
     * así que se atienden antes de mirar el `code`.
     */
    #[Route('/callback/{provider}', name: 'callback', methods: ['GET'])]
    public function callback(string $provider, Request $request, CompleteMailOauth $completeOauth): Response
    {
        $target = $this->providerFromOauthKey($provider);

        $error = $request->query->get('error');

        if (is_string($error) && '' !== $error) {
            $this->addFlash('error', 'Has cancelado la autorización. No se ha conectado ningún buzón.');

            return $this->redirectToRoute('app_mail_accounts_create', ['provider' => $target->value]);
        }

        if (!$this->rateLimits->acquire('mail_account', $this->organizationKey())) {
            return $this->tooManyRequests(
                'Has conectado demasiados buzones seguidos. Espera un minuto y vuelve a intentarlo.',
                'app_mail_accounts_create',
                ['provider' => $target->value],
            );
        }

        $code = $request->query->get('code');
        $state = $request->query->get('state');

        if (!is_string($code) || '' === $code || !is_string($state) || '' === $state) {
            $this->addFlash('error', 'La autorización ha llegado incompleta. Vuelve a empezar.');

            return $this->redirectToRoute('app_mail_accounts_create', ['provider' => $target->value]);
        }

        try {
            $account = $completeOauth($target, $code, $state, $this->oauthRedirectUri($target), $this->actorId());
        } catch (ImapConnectionException|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_mail_accounts_create', ['provider' => $target->value]);
        }

        $this->addFlash('success', sprintf('El buzón %s se ha conectado. Ya podemos empezar a buscar facturas.', $account->getEmailAddress()));

        return $this->redirectToRoute('app_mail_accounts_index');
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function show(EmailAccount $account): Response
    {
        return $this->render('mail/accounts/show.html.twig', [
            'account' => $account,
            'messageCount' => $this->messages->countForAccount($account->getId()),
            'runs' => $this->runs->findRecentForAccount($account->getId()),
            'forwardingForm' => $this->createForm(
                ForwardingFormType::class,
                ForwardingFormData::fromSenders($account->getForwardingSenders()),
                ['csrf_token_id' => 'mail_account_forwarding_'.$account->getId()->toRfc4122()],
            )->createView(),
        ]);
    }

    /**
     * Sincroniza el buzón a petición del usuario.
     *
     * La sincronización programada ya corre sola, pero poder lanzarla a mano es
     * lo que hace que el producto se sienta inmediato: conectas el correo y
     * quieres ver resultados, no esperar a la siguiente pasada.
     */
    #[Route('/{id}/sync', name: 'sync', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function sync(EmailAccount $account, Request $request, SyncEmailAccount $syncEmailAccount): Response
    {
        $this->assertCsrf($request, 'mail_account_sync_'.$account->getId()->toRfc4122());

        if (!$this->rateLimits->acquire('mail_account', $account->getOrganizationId()->toRfc4122())) {
            return $this->tooManyRequests(
                'Has pedido demasiadas sincronizaciones seguidas. Espera un minuto y vuelve a intentarlo.',
                'app_mail_accounts_show',
                ['id' => $account->getId()->toRfc4122()],
            );
        }

        try {
            $run = $syncEmailAccount($account, $this->actorId());
        } catch (ImapConnectionException|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_mail_accounts_show', ['id' => $account->getId()->toRfc4122()]);
        }

        $this->addFlash('success', sprintf(
            'Lectura terminada: %d mensajes revisados, %d nuevos, %d descubrimientos.',
            $run->getMessagesSeen(),
            $run->getMessagesProcessed(),
            $run->getDiscoveriesCreated(),
        ));

        return $this->redirectToRoute('app_mail_accounts_show', ['id' => $account->getId()->toRfc4122()]);
    }

    /**
     * Activa la ingesta por reenvío o cambia los remitentes autorizados.
     *
     * Es la alternativa a dar acceso al buzón (D-21): se entrega una dirección
     * dedicada y el usuario reenvía ahí sus facturas.
     */
    #[Route('/{id}/forwarding', name: 'forwarding', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function forwarding(EmailAccount $account, Request $request, EnableEmailForwarding $enableForwarding): Response
    {
        $data = ForwardingFormData::fromSenders($account->getForwardingSenders());
        $form = $this->createForm(ForwardingFormType::class, $data, [
            'csrf_token_id' => 'mail_account_forwarding_'.$account->getId()->toRfc4122(),
        ]);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'No hemos podido guardar los remitentes. Revisa las direcciones.');

            return $this->redirectToRoute('app_mail_accounts_show', ['id' => $account->getId()->toRfc4122()]);
        }

        $wasEnabled = $account->isForwardingEnabled();

        try {
            $enableForwarding($account, $data->toSenders(), $this->actorId());
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_mail_accounts_show', ['id' => $account->getId()->toRfc4122()]);
        }

        $this->addFlash('success', $wasEnabled
            ? 'Remitentes autorizados actualizados.'
            : 'Reenvío activado. Copia la dirección y configúrala en tu correo.');

        return $this->redirectToRoute('app_mail_accounts_show', ['id' => $account->getId()->toRfc4122()]);
    }

    #[Route('/{id}/forwarding/rotate', name: 'forwarding_rotate', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function rotateForwarding(EmailAccount $account, Request $request, RotateForwardingAddress $rotateForwardingAddress): Response
    {
        $this->assertCsrf($request, 'mail_account_forwarding_rotate_'.$account->getId()->toRfc4122());

        try {
            $rotateForwardingAddress($account, $this->actorId());
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_mail_accounts_show', ['id' => $account->getId()->toRfc4122()]);
        }

        $this->addFlash('success', 'Dirección cambiada. La anterior ya no acepta correo: actualízala en tu proveedor.');

        return $this->redirectToRoute('app_mail_accounts_show', ['id' => $account->getId()->toRfc4122()]);
    }

    #[Route('/{id}/forwarding/disable', name: 'forwarding_disable', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function disableForwarding(EmailAccount $account, Request $request, DisableEmailForwarding $disableEmailForwarding): Response
    {
        $this->assertCsrf($request, 'mail_account_forwarding_disable_'.$account->getId()->toRfc4122());

        $disableEmailForwarding($account, $this->actorId());

        $this->addFlash('success', 'Reenvío desactivado. La dirección deja de aceptar correo.');

        return $this->redirectToRoute('app_mail_accounts_show', ['id' => $account->getId()->toRfc4122()]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function edit(
        EmailAccount $account,
        Request $request,
        UpdateEmailAccount $updateEmailAccount,
        MailOauthProviderRegistry $oauthProviders,
    ): Response {
        $data = EmailAccountFormData::fromAccount($account);
        $form = $this->createForm(EmailAccountFormType::class, $data, ['is_new' => false]);
        $form->handleRequest($request);

        $context = [
            'form' => $form,
            'account' => $account,
            'oauth_providers' => $oauthProviders->configuredProviders(),
            'app_password_url' => $account->getProvider()->preset()?->appPasswordUrl,
        ];

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $updateEmailAccount($account, $data->toInput(), $this->actorId());
            } catch (ImapConnectionException|InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));

                return $this->render('mail/accounts/edit.html.twig', $context);
            }

            $this->addFlash('success', 'La configuración del buzón se ha actualizado.');

            return $this->redirectToRoute('app_mail_accounts_show', ['id' => $account->getId()->toRfc4122()]);
        }

        return $this->render('mail/accounts/edit.html.twig', $context);
    }

    #[Route('/{id}/test', name: 'test', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function test(EmailAccount $account, Request $request, TestEmailAccountConnection $testConnection): Response
    {
        $this->assertCsrf($request, 'mail_account_test_'.$account->getId()->toRfc4122());

        if (!$this->rateLimits->acquire('mail_account', $account->getOrganizationId()->toRfc4122())) {
            return $this->tooManyRequests(
                'Has probado la conexión demasiadas veces seguidas. Espera un minuto y vuelve a intentarlo.',
                'app_mail_accounts_show',
                ['id' => $account->getId()->toRfc4122()],
            );
        }

        try {
            $folders = $testConnection($account, $this->actorId());
        } catch (ImapConnectionException|InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_mail_accounts_show', ['id' => $account->getId()->toRfc4122()]);
        }

        $this->addFlash('success', sprintf('Conexión correcta. El buzón tiene %d carpetas.', count($folders)));

        return $this->redirectToRoute('app_mail_accounts_show', ['id' => $account->getId()->toRfc4122()]);
    }

    #[Route('/{id}/disconnect', name: 'disconnect', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function disconnect(EmailAccount $account, Request $request, DisconnectEmailAccount $disconnectEmailAccount): Response
    {
        $this->assertCsrf($request, 'mail_account_disconnect_'.$account->getId()->toRfc4122());

        $disconnectEmailAccount($account, $this->actorId());

        $this->addFlash('success', 'El buzón se ha desconectado. Dejaremos de leerlo, pero tus servicios y su historial siguen intactos.');

        return $this->redirectToRoute('app_mail_accounts_show', ['id' => $account->getId()->toRfc4122()]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function delete(EmailAccount $account, Request $request, DeleteEmailAccount $deleteEmailAccount): Response
    {
        $this->assertCsrf($request, 'mail_account_delete_'.$account->getId()->toRfc4122());

        $address = $account->getEmailAddress();
        $deleteEmailAccount($account, $this->actorId());

        $this->addFlash('success', sprintf('El buzón %s y todo lo leído de él se han eliminado.', $address));

        return $this->redirectToRoute('app_mail_accounts_index');
    }

    /**
     * La clave del límite de frecuencia es la organización activa.
     *
     * Sin organización activa no se puede limitar nada: la operación va a
     * fallar igualmente por falta de contexto, y no tiene sentido inventar
     * una clave.
     */
    private function organizationKey(): string
    {
        return $this->tenantContext->getOrganizationId()?->toRfc4122() ?? 'anonymous';
    }

    /**
     * Respuesta de "demasiado rápido".
     *
     * El código 429 es lo que le dice al navegador (y a cualquier cliente
     * que hable HTTP) que el problema es el ritmo, no la petición: así puede
     * reintentarlo más tarde sin que el usuario piense que la aplicación está
     * rota.
     */
    /**
     * @param array<string, string> $parameters
     */
    private function tooManyRequests(string $message, string $route, array $parameters): Response
    {
        $this->addFlash('error', $message);

        $response = $this->redirectToRoute($route, $parameters);
        $response->headers->set('Retry-After', '60');
        $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);

        return $response;
    }

    /**
     * El proveedor viaja en la URL como su clave de OAuth (`google`,
     * `microsoft`), no como el valor del enum: es lo que se declara en la
     * consola del proveedor y lo que aparece en el `redirect_uri`.
     */
    private function providerFromOauthKey(string $key): EmailAccountProvider
    {
        foreach (EmailAccountProvider::cases() as $provider) {
            if ($provider->oauthKey() === $key) {
                return $provider;
            }
        }

        throw $this->createNotFoundException(sprintf('El proveedor «%s» no existe.', $key));
    }

    private function oauthRedirectUri(EmailAccountProvider $provider): string
    {
        return $this->generateUrl(
            'app_mail_accounts_callback',
            ['provider' => (string) $provider->oauthKey()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    private function actorId(): ?Uuid
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getId() : null;
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }
    }
}
