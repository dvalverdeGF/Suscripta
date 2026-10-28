<?php

declare(strict_types=1);

namespace App\Billing\UI\Controller;

use App\Billing\Application\CheckoutUnavailableException;
use App\Billing\Application\StartCheckout;
use App\Billing\Domain\Exception\GatewayNotConfiguredException;
use App\Billing\Domain\Exception\GatewayRequestException;
use App\Identity\Domain\Entity\User;
use App\Subscriptions\Domain\Repository\PlanPriceRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Abre el pago de un plan (Fase 24).
 *
 * ## `GET` y no `POST`
 *
 * El botón de contratar es un enlace: no cambia nada en Suscripta, solo abre
 * una sesión de pago en la pasarela. La suscripción se crea cuando se cobra,
 * así que no hay nada que un POST protegería —y un POST obligaría a un token
 * CSRF en la página de precios, que ve gente sin sesión.
 *
 * ## Todo lo que puede salir mal se dice en castellano
 *
 * Un precio retirado, un plan gratuito, una suscripción ya viva o una pasarela
 * sin configurar no son errores del programa: son situaciones que el cliente
 * tiene que entender. Se convierten en un aviso y se le devuelve a la página de
 * precios, no a una pantalla de error.
 */
final class CheckoutController extends AbstractController
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    #[Route('/checkout/{priceId}', name: 'app_checkout', methods: ['GET'], requirements: ['priceId' => '[0-9a-fA-F-]{36}'])]
    #[IsGranted('ROLE_USER')]
    public function checkout(string $priceId, PlanPriceRepositoryInterface $prices, StartCheckout $startCheckout): Response
    {
        if (!Uuid::isValid($priceId)) {
            throw $this->createNotFoundException('Ese precio no existe.');
        }

        $price = $prices->find(Uuid::fromString($priceId));

        if (null === $price) {
            throw $this->createNotFoundException('Ese precio no existe.');
        }

        try {
            $session = $startCheckout($price, $this->customerEmail());
        } catch (CheckoutUnavailableException|GatewayNotConfiguredException $exception) {
            $this->addFlash('warning', $exception->getMessage());

            return $this->redirectToRoute('app_site_pricing');
        } catch (GatewayRequestException $exception) {
            $this->logger->error('No se ha podido abrir el checkout.', [
                'price' => $priceId,
                'status' => $exception->statusCode,
                'error' => $exception->getMessage(),
            ]);

            $this->addFlash('danger', 'No hemos podido abrir el pago. Inténtalo de nuevo en un par de minutos.');

            return $this->redirectToRoute('app_site_pricing');
        }

        return new RedirectResponse($session->url);
    }

    /**
     * El correo del usuario se copia al checkout para que Stripe lo proponga y
     * el cliente no tenga que escribirlo otra vez. No se usa para identificar
     * la organización: eso viaja en los metadatos.
     */
    private function customerEmail(): ?string
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return null;
        }

        $email = $user->getEmail();

        return '' === $email ? null : $email;
    }
}
