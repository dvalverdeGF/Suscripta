<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Entity\User;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;
use DateInterval;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Envía el correo de verificación de la dirección.
 *
 * El enlace va firmado con `UriSigner` (HMAC sobre `APP_SECRET`) y caduca a las
 * 24 horas, así que no hace falta guardar ningún token en la base de datos: el
 * propio enlace es la prueba. Ver SECURITY.md §3.
 */
final readonly class SendVerificationEmail
{
    private const string VALIDITY = 'PT24H';

    public function __construct(
        private MailerInterface $mailer,
        private RouterInterface $router,
        private UriSigner $uriSigner,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(User $user): void
    {
        if ($user->isVerified()) {
            return;
        }

        $url = $this->router->generate(
            'app_verify_email',
            ['id' => $user->getId()->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        // `UriSigner` espera un instante, no una duración: pasarle 86400 lo
        // interpreta como el timestamp 86400 (1970) y el enlace nace caducado.
        $signedUrl = $this->uriSigner->sign($url, new DateInterval(self::VALIDITY));

        $email = new TemplatedEmail()
            ->to($user->getEmail())
            ->subject('Confirma tu correo en Suscripta')
            ->htmlTemplate('emails/verify_email.html.twig')
            ->textTemplate('emails/verify_email.txt.twig')
            ->context([
                'displayName' => $user->getDisplayName(),
                'signedUrl' => $signedUrl,
                'expiresInHours' => 24,
            ]);

        $this->mailer->send($email);

        $this->auditLogger->log(
            action: AuditAction::USER_EMAIL_VERIFICATION_SENT,
            targetType: 'user',
            targetId: $user->getId()->toRfc4122(),
        );
    }
}
