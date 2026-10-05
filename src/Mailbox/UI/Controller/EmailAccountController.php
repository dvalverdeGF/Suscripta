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
use App\Mailbox\Application\SyncEmailAccount;
use App\Mailbox\Application\TestEmailAccountConnection;
use App\Mailbox\Application\UpdateEmailAccount;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Exception\ImapConnectionException;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailSyncRunRepositoryInterface;
use App\Mailbox\UI\Form\EmailAccountFormData;
use App\Mailbox\UI\Form\EmailAccountFormType;
use App\Mailbox\UI\Form\ForwardingFormData;
use App\Mailbox\UI\Form\ForwardingFormType;
use App\Shared\Domain\Exception\InvalidArgumentException;

use function count;
use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
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

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, ConnectEmailAccount $connectEmailAccount): Response
    {
        $data = new EmailAccountFormData();
        $form = $this->createForm(EmailAccountFormType::class, $data, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
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

        return $this->render('mail/accounts/new.html.twig', [
            'form' => $form,
        ]);
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
    public function edit(EmailAccount $account, Request $request, UpdateEmailAccount $updateEmailAccount): Response
    {
        $data = EmailAccountFormData::fromAccount($account);
        $form = $this->createForm(EmailAccountFormType::class, $data, ['is_new' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $updateEmailAccount($account, $data->toInput(), $this->actorId());
            } catch (ImapConnectionException|InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));

                return $this->render('mail/accounts/edit.html.twig', [
                    'form' => $form,
                    'account' => $account,
                ]);
            }

            $this->addFlash('success', 'La configuración del buzón se ha actualizado.');

            return $this->redirectToRoute('app_mail_accounts_show', ['id' => $account->getId()->toRfc4122()]);
        }

        return $this->render('mail/accounts/edit.html.twig', [
            'form' => $form,
            'account' => $account,
        ]);
    }

    #[Route('/{id}/test', name: 'test', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function test(EmailAccount $account, Request $request, TestEmailAccountConnection $testConnection): Response
    {
        $this->assertCsrf($request, 'mail_account_test_'.$account->getId()->toRfc4122());

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
