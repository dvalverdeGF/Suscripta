<?php

declare(strict_types=1);

namespace App\Mailbox\UI\Controller;

use App\Identity\Domain\Entity\User;
use App\Mailbox\Application\ConnectEmailAccount;
use App\Mailbox\Application\DeleteEmailAccount;
use App\Mailbox\Application\DisconnectEmailAccount;
use App\Mailbox\Application\TestEmailAccountConnection;
use App\Mailbox\Application\UpdateEmailAccount;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Exception\ImapConnectionException;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Mailbox\UI\Form\EmailAccountFormData;
use App\Mailbox\UI\Form\EmailAccountFormType;
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
        ]);
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
