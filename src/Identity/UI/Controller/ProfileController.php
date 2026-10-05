<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Application\SendVerificationEmail;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Identity\UI\Form\ChangePasswordFormType;
use App\Identity\UI\Form\ProfileFormType;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/settings/profile', name: 'app_settings_profile')]
final class ProfileController extends AbstractController
{
    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function profile(
        Request $request,
        UserRepositoryInterface $users,
        SendVerificationEmail $sendVerificationEmail,
    ): Response {
        $user = $this->requireUser();

        $form = $this->createForm(ProfileFormType::class, ProfileFormType::fromUser($user));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{displayName: string, email: string, locale: string, timezone: string} $data */
            $data = $form->getData();

            $emailChanged = $data['email'] !== $user->getEmail();

            if ($emailChanged && $users->emailExists($data['email'])) {
                $form->get('email')->addError(new FormError('Ya existe una cuenta con este correo.'));

                return $this->renderProfile($form);
            }

            $user->setDisplayName($data['displayName']);
            $user->setLocale($data['locale']);
            $user->setTimezone($data['timezone']);

            if ($emailChanged) {
                $user->setEmail($data['email']);
                $user->unverify();
            }

            $users->save($user);

            if ($emailChanged) {
                $sendVerificationEmail($user);
                $this->addFlash('success', 'Datos guardados. Te hemos enviado un correo para confirmar la dirección nueva.');
            } else {
                $this->addFlash('success', 'Datos guardados.');
            }

            return $this->redirectToRoute('app_settings_profile');
        }

        return $this->renderProfile($form);
    }

    #[Route('/password', name: '_password', methods: ['POST'])]
    public function changePassword(
        Request $request,
        UserRepositoryInterface $users,
        UserPasswordHasherInterface $passwordHasher,
        AuditLoggerInterface $auditLogger,
    ): Response {
        $user = $this->requireUser();

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('error', $error->getMessage());
            }

            return $this->redirectToRoute('app_settings_profile');
        }

        /** @var string $currentPassword */
        $currentPassword = $form->get('currentPassword')->getData();
        /** @var string $newPassword */
        $newPassword = $form->get('plainPassword')->getData();

        if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
            $this->addFlash('error', 'La contraseña actual no es correcta.');

            return $this->redirectToRoute('app_settings_profile');
        }

        $user->setPasswordHash($passwordHasher->hashPassword($user, $newPassword));
        $users->save($user);

        $auditLogger->log(
            action: AuditAction::USER_PASSWORD_CHANGED,
            targetType: 'user',
            targetId: $user->getId()->toRfc4122(),
            metadata: ['via' => 'profile'],
        );

        $this->addFlash('success', 'Contraseña actualizada.');

        return $this->redirectToRoute('app_settings_profile');
    }

    private function requireUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * @param FormInterface<array{displayName: string, email: string, locale: string, timezone: string}> $form
     */
    private function renderProfile(FormInterface $form): Response
    {
        return $this->render('identity/profile.html.twig', [
            'form' => $form,
            'password_form' => $this->createForm(ChangePasswordFormType::class),
        ]);
    }
}
