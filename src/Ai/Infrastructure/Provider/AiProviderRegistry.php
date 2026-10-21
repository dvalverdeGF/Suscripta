<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure\Provider;

use App\Ai\Domain\Contract\AiProviderInterface;
use App\Ai\Domain\Contract\AiProviderRegistryInterface;
use App\Ai\Domain\Enum\AiTier;
use App\Integrations\Application\IntegrationRegistry;

use function array_filter;
use function array_values;
use function iterator_to_array;

/**
 * Registro de proveedores de IA, construido desde el registro de integraciones.
 *
 * ## Una sola fuente de verdad
 *
 * Los proveedores de IA **son** integraciones: tienen credenciales, se
 * configuran en el panel y se pueden desactivar. Por eso el registro de IA no
 * mantiene su propia lista, sino que la **deriva** de las integraciones marcadas
 * como `isAiProvider`.
 *
 * La consecuencia práctica: no puede existir un proveedor de IA visible en el
 * panel que el motor no conozca, ni al revés.
 */
final class AiProviderRegistry implements AiProviderRegistryInterface
{
    /** @var list<AiProviderInterface> */
    private array $providers;

    /**
     * @param iterable<AiProviderInterface> $providers
     */
    public function __construct(
        iterable $providers,
        private readonly IntegrationRegistry $integrations,
    ) {
        $this->providers = array_values(array_filter(
            iterator_to_array($providers, false),
            fn (AiProviderInterface $provider): bool => $this->integrations->knows($provider->key()),
        ));
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
        return array_values(array_map(
            static fn (AiProviderInterface $provider): string => $provider->key(),
            $this->providers,
        ));
    }

    public function byTier(AiTier $tier): array
    {
        return array_values(array_filter(
            $this->providers,
            static fn (AiProviderInterface $provider): bool => $provider->tier() === $tier,
        ));
    }
}
