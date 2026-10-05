<?php

declare(strict_types=1);

namespace App\Identity\UI\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class OrganizationContextExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('user_organizations', [OrganizationContextRuntime::class, 'organizations']),
            new TwigFunction('active_organization_id', [OrganizationContextRuntime::class, 'activeOrganizationId']),
            new TwigFunction('is_active_organization', [OrganizationContextRuntime::class, 'isActive']),
        ];
    }
}
