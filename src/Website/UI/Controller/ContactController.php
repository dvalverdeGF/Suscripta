<?php

declare(strict_types=1);

namespace App\Website\UI\Controller;

use App\Website\Application\SendContactMessage;
use App\Website\UI\Form\ContactFormType;
use App\Website\UI\Form\ContactMessageData;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * El formulario de contacto de la web.
 *
 * ## Por qué hay límite de frecuencia
 *
 * Es el único formulario público que manda correo a una dirección nuestra. Sin
 * límite, cualquiera puede usarlo para llenar la bandeja de entrada —o para
 * usar nuestro buzón como origen de spam—. El límite es por IP y generoso:
 * quien escribe de verdad manda uno o dos mensajes.
 *
 * ## Por qué el éxito no dice «enviado» a secas
 *
 * Porque el envío puede fallar en el servidor de correo y el usuario no tiene
 * forma de saberlo. Si el transporte falla, la excepción sube: es preferible un
 * error visible a un «gracias» que se traga el mensaje.
 */
final class ContactController extends AbstractController
{
    #[Route('/contacto', name: 'app_site_contact', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        SendContactMessage $sendContactMessage,
        #[Autowire(service: 'limiter.contact_form')]
        RateLimiterFactoryInterface $limiterFactory,
    ): Response {
        $data = new ContactMessageData();
        $form = $this->createForm(ContactFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limiter = $limiterFactory->create($request->getClientIp() ?? 'desconocida');

            if (!$limiter->consume()->isAccepted()) {
                $this->addFlash('error', 'Has enviado varios mensajes seguidos. Espera un rato y vuelve a intentarlo, o escríbenos directamente al correo de contacto.');

                return $this->render('website/contact.html.twig', ['form' => $form]);
            }

            $sendContactMessage($data);

            // Redirect y no render: recargar la página después de enviar no
            // puede volver a mandar el correo.
            $this->addFlash('success', 'Mensaje enviado. Te contestamos en el correo que nos has dejado, normalmente en un día laborable.');

            return $this->redirectToRoute('app_site_contact');
        }

        return $this->render('website/contact.html.twig', [
            'form' => $form,
        ]);
    }
}
