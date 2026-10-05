<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig;

use Symfony\Component\Routing\RouterInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Permite a las plantillas preguntar si una ruta existe.
 *
 * Se usa en la navegación para no romper la interfaz mientras una sección
 * todavía no está implementada.
 */
final class RouteExtension extends AbstractExtension
{
    public function __construct(private readonly RouterInterface $router)
    {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('route_exists', $this->routeExists(...)),
        ];
    }

    public function routeExists(string $name): bool
    {
        return null !== $this->router->getRouteCollection()->get($name);
    }
}
