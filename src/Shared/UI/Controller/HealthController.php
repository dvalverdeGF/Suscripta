<?php

declare(strict_types=1);

namespace App\Shared\UI\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Sonda de disponibilidad. No expone información interna: solo si la base de
 * datos responde.
 */
final class HealthController extends AbstractController
{
    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function __invoke(Connection $connection): JsonResponse
    {
        $database = 'ok';

        try {
            $connection->executeQuery('SELECT 1');
        } catch (Throwable) {
            $database = 'error';
        }

        return new JsonResponse(
            ['status' => 'ok' === $database ? 'ok' : 'degraded', 'database' => $database],
            'ok' === $database ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
