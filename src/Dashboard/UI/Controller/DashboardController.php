<?php

declare(strict_types=1);

namespace App\Dashboard\UI\Controller;

use App\Dashboard\Application\BuildDashboardSummary;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * El panel: la primera pantalla que ve el usuario y la que tiene que responder
 * a «¿qué estoy pagando?» sin que tenga que buscar nada.
 *
 * Vive en `/dashboard` y no en `/` desde la fase 23: la raíz es la web
 * corporativa, y a un visitante sin sesión no se le puede enseñar un panel que
 * no puede usar. El nombre de la ruta no cambia, así que los enlaces y el
 * `default_target_path` del login siguen apuntando aquí sin tocarlos.
 */
final class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(BuildDashboardSummary $buildSummary): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'summary' => $buildSummary(),
        ]);
    }
}
