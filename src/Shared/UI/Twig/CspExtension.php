<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig;

use App\Shared\UI\EventListener\SecurityHeadersListener;

use function is_string;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Da acceso a la plantilla al nonce de la Content-Security-Policy.
 *
 * El nonce se genera una vez por petición (`SecurityHeadersListener`) y lo
 * necesitan los `<script>` en línea: sin él, la CSP los bloquea.
 */
final class CspExtension extends AbstractExtension
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', $this->nonce(...)),
        ];
    }

    public function nonce(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return '';
        }

        $nonce = $request->attributes->get(SecurityHeadersListener::NONCE_ATTRIBUTE);

        return is_string($nonce) ? $nonce : '';
    }
}
