<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Application\RegisterUser;
use App\Identity\UI\Form\RegistrationFormType;
use App\Shared\Domain\Exception\InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        RegisterUser $registerUser,
        Security $security,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $form = $this->createForm(RegistrationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{displayName: string, email: string} $data */
            $data = $form->getData();
            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            try {
                $user = $registerUser($data['email'], $plainPassword, $data['displayName']);
            } catch (InvalidArgumentException $exception) {
                // El error de negocio se ancla al campo para que se muestre junto a
                // él y la respuesta sea 422 (formulario inválido), no un 200.
                $form->get('email')->addError(new FormError($exception->getMessage()));

                return $this->render('security/register.html.twig', ['form' => $form]);
            }

            $this->addFlash('success', 'Cuenta creada. Bienvenido a Suscripta.');

            $security->login($user, 'form_login', 'main');

            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('security/register.html.twig', ['form' => $form]);
    }
}
