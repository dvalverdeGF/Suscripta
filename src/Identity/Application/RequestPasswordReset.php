<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Entity\PasswordResetRequest;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\PasswordResetRequestRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;
use DateTimeImmutable;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Emite un enlace de restablecimiento de contraseña.
 *
 * Devuelve siempre `void` y no revela si el correo existe: la respuesta HTTP es
 * idéntica en ambos casos para no permitir enumerar cuentas (SECURITY.md §3).
 */
final readonly class RequestPasswordReset
{
    private const int VALIDITY = 3600;

    public function __construct(
        private UserRepositoryInterface $users,
        private PasswordResetRequestRepositoryInterface $requests,
        private MailerInterface $mailer,
        private RouterInterface $router,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(string $email, ?string $requestedIp = null): void
    {
        $user = $this->users->findByEmail($email);

        if (!$user instanceof User) {
            return;
        }

        $now = new DateTimeImmutable();
        $this->requests->invalidatePendingFor($user, $now);

        $token = bin2hex(random_bytes(32));

        $request = new PasswordResetRequest(
            user: $user,
            tokenHash: self::hash($token),
            expiresAt: $now->modify('+'.self::VALIDITY.' seconds'),
            requestedIp: $requestedIp,
        );

        $this->requests->save($request);

        $url = $this->router->generate(
            'app_reset_password',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $message = new TemplatedEmail()
            ->to($user->getEmail())
            ->subject('Restablece tu contraseña de Suscripta')
            ->htmlTemplate('emails/reset_password.html.twig')
            ->textTemplate('emails/reset_password.txt.twig')
            ->context([
                'displayName' => $user->getDisplayName(),
                'resetUrl' => $url,
                'expiresInMinutes' => self::VALIDITY / 60,
            ]);

        $this->mailer->send($message);

        $this->auditLogger->log(
            action: AuditAction::USER_PASSWORD_RESET_REQUESTED,
            targetType: 'user',
            targetId: $user->getId()->toRfc4122(),
        );
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
