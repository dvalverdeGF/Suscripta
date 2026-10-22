<?php

declare(strict_types=1);

namespace App\Demo\UI\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class DemoAccountExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('demo_account', [DemoAccountRuntime::class, 'account']),
        ];
    }
}
