<?php

declare(strict_types=1);

namespace App\Tests\Support\Ai;

use App\Ai\Domain\Contract\AiProviderInterface;
use App\Ai\Domain\Contract\AiProviderRegistryInterface;
use App\Ai\Domain\Enum\AiTier;

/**
 * Registro de proveedores de IA de mentira.
 *
 * Permite comprobar qué proveedores están «configurados» sin tocar la base de
 * datos ni el registro de integraciones.
 */
final class FakeAiProviderRegistry implements AiProviderRegistryInterface
{
    /**
     * @param list<AiProviderInterface> $providers
     */
    public function __construct(private array $providers = [])
    {
    }

    public function all(): array
    {
        return $this->providers;
    }

    public function find(string $key): ?AiProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->key() === $key) {
                return $provider;
            }
        }

        return null;
    }

    public function knows(string $key): bool
    {
        return null !== $this->find($key);
    }

    public function keys(): array
    {
        return array_map(
            static fn (AiProviderInterface $provider): string => $provider->key(),
            $this->providers,
        );
    }

    public function byTier(AiTier $tier): array
    {
        return array_values(array_filter(
            $this->providers,
            static fn (AiProviderInterface $provider): bool => $provider->tier() === $tier,
        ));
    }
}
