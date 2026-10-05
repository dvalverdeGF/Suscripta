<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Application\SendVerificationEmail;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Verificación de la dirección de correo.
 *
 * La verificación es **blanda**: no bloquea el acceso a la aplicación, pero sí
 * es requisito para conectar un buzón (DECISIONS.md D-40). Obligar a verificar
 * antes de entrar añadiría fricción justo en el momento en el que queremos que
 * la persona pruebe el producto.
 */
final class VerifyEmailController extends AbstractController
{
    #[Route('/verify/email', name: 'app_verify_email', methods: ['GET'])]
    public function verify(
        Request $request,
        UriSigner $uriSigner,
        UserRepositoryInterface $users,
        AuditLoggerInterface $auditLogger,
    ): Response {
        $id = $request->query->getString('id');

        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException('Enlace de verificación no válido.');
        }

        if (!$uriSigner->checkRequest($request)) {
            $this->addFlash('error', 'El enlace de verificación no es válido o ha caducado. Pide uno nuevo.');

            return $this->redirectToRoute('app_dashboard');
        }

        $user = $users->find(Uuid::fromString($id));

        if (!$user instanceof User) {
            throw $this->createNotFoundException('Enlace de verificación no válido.');
        }

        if ($user->isVerified()) {
            $this->addFlash('info', 'Tu correo ya estaba confirmado.');

            return $this->redirectToRoute('app_dashboard');
        }

        $user->markVerified();
        $users->save($user);

        $auditLogger->log(
            action: AuditAction::USER_EMAIL_VERIFIED,
            targetType: 'user',
            targetId: $user->getId()->toRfc4122(),
        );

        $this->addFlash('success', 'Correo confirmado. Ya puedes conectar tu buzón de facturación.');

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/verify/email/resend', name: 'app_verify_email_resend', methods: ['POST'])]
    public function resend(
        Request $request,
        SendVerificationEmail $sendVerificationEmail,
    ): Response {
        $this->assertCsrf($request, 'resend_verification');

        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        if ($user->isVerified()) {
            $this->addFlash('info', 'Tu correo ya estaba confirmado.');

            return $this->redirectToRoute('app_dashboard');
        }

        $sendVerificationEmail($user);

        $this->addFlash('success', 'Te hemos enviado un correo nuevo. Revisa también la carpeta de spam.');

        return $this->redirectToRoute('app_dashboard');
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }
    }
}
