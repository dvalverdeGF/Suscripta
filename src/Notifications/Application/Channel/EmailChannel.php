<?php

declare(strict_types=1);

namespace App\Notifications\Application\Channel;

use App\Identity\Domain\Entity\User;
use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\NotificationChannel;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

/**
 * Entrega el aviso por correo electrónico.
 *
 * Es el único canal que sale del sistema, así que es el único que puede fallar
 * por causas ajenas. Un fallo se convierte en un resultado `failed()` y no en
 * una excepción: que el servidor de correo esté caído no puede impedir que el
 * resto de avisos se registren.
 *
 * El correo lleva el aviso entero en el cuerpo, no un «tienes un aviso nuevo»:
 * si el usuario tiene que entrar a la aplicación para saber de qué va, el aviso
 * por correo no aporta nada.
 */
final readonly class EmailChannel implements NotificationChannelInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private RouterInterface $router,
    ) {
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::EMAIL;
    }

    public function deliver(Alert $alert, User $user): DeliveryResult
    {
        $url = $this->router->generate('app_alerts_index', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = new TemplatedEmail()
            ->to($user->getEmail())
            ->subject($alert->getTitle())
            ->htmlTemplate('emails/alert.html.twig')
            ->textTemplate('emails/alert.txt.twig')
            ->context([
                'displayName' => $user->getDisplayName(),
                'title' => $alert->getTitle(),
                'message' => $alert->getMessage(),
                'severity' => $alert->getSeverity(),
                'type' => $alert->getType(),
                'dueAt' => $alert->getDueAt(),
                'url' => $url,
            ]);

        try {
            $this->mailer->send($email);
        } catch (Throwable $e) {
            return DeliveryResult::failed($e->getMessage());
        }

        return DeliveryResult::sent();
    }
}
