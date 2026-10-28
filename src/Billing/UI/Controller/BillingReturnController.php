<?php

declare(strict_types=1);

namespace App\Billing\UI\Controller;

use App\Billing\Application\CompleteCheckout;
use App\Billing\Domain\Exception\GatewayRequestException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * La pantalla de «gracias» al volver de la pasarela (Fase 24).
 *
 * ## Aplica la suscripción aquí, no en el webhook
 *
 * El cliente acaba de pagar y está mirando la pantalla: tiene que ver su plan
 * activo ahora. `CompleteCheckout` pregunta a la pasarela por la sesión y, si
 * está completa, aplica la suscripción en el acto. El webhook llegará después y
 * hará lo mismo con los mismos datos (es idempotente), así que no importa quién
 * llegue primero.
 *
 * ## Si no se puede confirmar, no se miente
 *
 * Cuando la pasarela no responde o la sesión aún no está completa, la pantalla
 * dice que el pago se está confirmando en lugar de afirmar que se ha cobrado.
 * Enseñar «todo listo» sobre un pago que no consta es la peor versión de este
 * error.
 */
final class BillingReturnController extends AbstractController
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    #[Route('/checkout/gracias', name: 'app_billing_return', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request, CompleteCheckout $completeCheckout): Response
    {
        $sessionId = $request->query->getString('session_id');

        $confirmed = false;

        if ('' !== $sessionId) {
            try {
                $confirmed = $completeCheckout($sessionId);
            } catch (GatewayRequestException $exception) {
                $this->logger->warning('No se ha podido confirmar el checkout al volver.', [
                    'session' => $sessionId,
                    'status' => $exception->statusCode,
                ]);
            }
        }

        return $this->render('billing/return.html.twig', [
            'confirmed' => $confirmed,
        ]);
    }
}
