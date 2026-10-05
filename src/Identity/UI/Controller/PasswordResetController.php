<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Application\RequestPasswordReset;
use App\Identity\Application\ResetPassword;
use App\Identity\UI\Form\PasswordResetRequestFormType;
use App\Identity\UI\Form\ResetPasswordFormType;
use App\Shared\Domain\Exception\InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PasswordResetController extends AbstractController
{
    #[Route('/reset-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function request(Request $request, RequestPasswordReset $requestPasswordReset): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $form = $this->createForm(PasswordResetRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string} $data */
            $data = $form->getData();

            ($requestPasswordReset)($data['email'], $request->getClientIp());

            // Misma respuesta exista o no la cuenta: no se puede enumerar usuarios.
            return $this->redirectToRoute('app_check_email');
        }

        return $this->render('identity/forgot_password.html.twig', ['form' => $form]);
    }

    #[Route('/reset-password/enviado', name: 'app_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        return $this->render('identity/check_email.html.twig');
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password', methods: ['GET', 'POST'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function reset(string $token, Request $request, ResetPassword $resetPassword): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $form = $this->createForm(ResetPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{plainPassword: string} $data */
            $data = $form->getData();

            try {
                ($resetPassword)($token, $data['plainPassword']);
            } catch (InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));

                return $this->render('identity/reset_password.html.twig', [
                    'form' => $form,
                    'token' => $token,
                ]);
            }

            $this->addFlash('success', 'Contraseña actualizada. Ya puedes entrar con la nueva.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('identity/reset_password.html.twig', [
            'form' => $form,
            'token' => $token,
        ]);
    }
}
