<?php

declare(strict_types=1);

namespace App\Website\UI\Controller;

use App\Billing\Domain\Port\PaymentGatewayInterface;
use App\Website\Application\BuildPricingPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La página de precios, leída del catálogo comercial (Fase 22).
 *
 * Si el catálogo está vacío la página sigue funcionando y lo dice: una web que
 * enseña tarjetas vacías o un error 500 porque nadie ha sembrado la base de
 * datos es una web que se cae el día del despliegue.
 */
final class PricingController extends AbstractController
{
    #[Route('/precios', name: 'app_site_pricing', methods: ['GET'])]
    public function __invoke(BuildPricingPage $pricing, PaymentGatewayInterface $gateway): Response
    {
        return $this->render('website/pricing.html.twig', [
            'plans' => $pricing(),
            // Sin pasarela configurada no se ofrece contratar: un botón que
            // lleva a «falta la clave secreta» es peor que no tenerlo.
            'checkoutEnabled' => $gateway->isConfigured(),
        ]);
    }
}
