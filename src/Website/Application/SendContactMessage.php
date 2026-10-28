<?php

declare(strict_types=1);

namespace App\Website\Application;

use App\Website\UI\Form\ContactMessageData;

use function sprintf;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Manda a nuestro buzón el mensaje escrito en la web corporativa.
 *
 * El correo va **con `Reply-To`**, no con el remitente cambiado: poner en el
 * `From` la dirección de quien escribe es la forma más rápida de que el mensaje
 * acabe en spam, porque el dominio del remitente no es el nuestro y falla
 * SPF/DKIM. Con `Reply-To` se contesta igual de rápido y el correo llega.
 *
 * No se guarda nada en la base de datos: el buzón es el archivo, y así no hay
 * datos personales que retener, exportar ni borrar.
 */
final readonly class SendContactMessage
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%app.site.contact_email%')]
        private string $contactEmail,
    ) {
    }

    public function __invoke(ContactMessageData $data): void
    {
        $email = new TemplatedEmail()
            ->to(new Address($this->contactEmail))
            ->replyTo(new Address($data->email, $data->name))
            ->subject(sprintf(
                '[Web] %s — %s',
                ContactMessageData::topicLabel($data->topic),
                $data->name,
            ))
            ->htmlTemplate('emails/contact.html.twig')
            ->textTemplate('emails/contact.txt.twig')
            ->context([
                'name' => $data->name,
                // `email` es una variable reservada en `TemplatedEmail`: la
                // ocupa el propio mensaje. La dirección va como `sender`.
                'sender' => $data->email,
                'company' => $data->company,
                'topic' => ContactMessageData::topicLabel($data->topic),
                'message' => $data->message,
            ]);

        $this->mailer->send($email);
    }
}
