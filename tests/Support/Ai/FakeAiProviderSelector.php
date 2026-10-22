<?php

declare(strict_types=1);

namespace App\Tests\Support\Ai;

use App\Ai\Domain\Contract\AiProviderException;
use App\Ai\Domain\Contract\AiProviderInterface;
use App\Ai\Domain\Contract\AiProviderSelectorInterface;
use App\Ai\Domain\Enum\AiPurpose;

/**
 * Selector de proveedor de IA de mentira.
 *
 * Devuelve siempre el mismo proveedor (o ninguno), que es lo que necesitan
 * las pruebas de la escalera de IA: lo que se comprueba es qué hace el
 * pipeline con la respuesta, no cómo se elige el proveedor.
 */
final class FakeAiProviderSelector implements AiProviderSelectorInterface
{
    /**
     * @param AiProviderInterface|null $provider `null` simula que la
     *                                           organización no ha configurado
     *                                           ningún proveedor
     */
    public function __construct(private readonly ?AiProviderInterface $provider = null)
    {
    }

    public function forPurpose(AiPurpose $purpose): AiProviderInterface
    {
        if (null === $this->provider) {
            throw AiProviderException::notAvailableForPurpose($purpose->value);
        }

        return $this->provider;
    }

    public function advanced(): AiProviderInterface
    {
        if (null === $this->provider) {
            throw AiProviderException::noAdvancedProvider();
        }

        return $this->provider;
    }

    public function has(AiPurpose $purpose): bool
    {
        return null !== $this->provider;
    }

    public function configuredKeys(): array
    {
        return null !== $this->provider ? [$this->provider->key()] : [];
    }
}
