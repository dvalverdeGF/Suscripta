<?php

declare(strict_types=1);

namespace App\Website\UI\Controller;

use App\Billing\Domain\Port\PaymentGatewayInterface;
use App\Website\Application\BuildPricingPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * La portada: la web corporativa para quien no ha entrado y el panel para quien
 * ya está dentro.
 *
 * ## Por qué la misma URL hace dos cosas
 *
 * La alternativa era tener la web en un dominio y la aplicación en otro, con
 * dos despliegues y el diseño duplicado. Aquí `/` bifurca: sin sesión enseña la
 * portada, con sesión manda al panel. El usuario que ya es cliente no tiene que
 * recordar una URL distinta, y el visitante que llega por primera vez ve el
 * producto en lugar de un formulario de login (D-83).
 *
 * La bifurcación es un **redirect** y no un render del panel en la misma ruta:
 * así cada pantalla tiene una sola URL, el botón «atrás» se comporta y el panel
 * conserva sus enlaces (`app_dashboard`) sin duplicar rutas.
 */
final class HomepageController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function __invoke(BuildPricingPage $pricing, PaymentGatewayInterface $gateway): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('website/home.html.twig', [
            'plans' => $pricing(),
            'checkoutEnabled' => $gateway->isConfigured(),
        ]);
    }
}
