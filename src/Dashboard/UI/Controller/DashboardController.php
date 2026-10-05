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
 */
final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(BuildDashboardSummary $buildSummary): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'summary' => $buildSummary(),
        ]);
    }
}
