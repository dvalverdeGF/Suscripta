<?php

declare(strict_types=1);

namespace App\Processing\Domain\Provider;

use App\Catalog\Domain\Entity\Provider;
use Symfony\Component\Uid\Uuid;

/**
 * Resultado de intentar reconocer al proveedor de un correo
 * (ARCHITECTURE.md §13.7).
 *
 * Distingue tres situaciones que el pipeline trata de forma distinta:
 *
 * - **Conocido** (`provider` no nulo): hay una identidad en el catálogo, así que
 *   el nombre es fiable y se puede intentar un parser.
 * - **Provisional** (`provider` nulo, `provisionalName` no nulo): no lo
 *   conocemos, pero tenemos un nombre razonable para proponer. Es el caso de la
 *   primera factura de cualquier proveedor nuevo, y sin él el producto no podría
 *   descubrir nada.
 * - **Desconocido** (ambos nulos): ni siquiera hay un nombre que enseñar.
 */
final readonly class ProviderMatch
{
    public function __construct(
        public ?Provider $provider = null,
        public ?string $provisionalName = null,
        public ?string $matchedBy = null,
        public int $confidence = 0,
    ) {
    }

    public static function unknown(): self
    {
        return new self();
    }

    public static function provisional(string $name, string $matchedBy = 'sender'): self
    {
        return new self(provisionalName: $name, matchedBy: $matchedBy, confidence: 40);
    }

    public function isKnown(): bool
    {
        return null !== $this->provider;
    }

    /**
     * Nombre que se puede enseñar al usuario, sea verificado o provisional.
     */
    public function displayName(): ?string
    {
        return $this->provider?->getName() ?? $this->provisionalName;
    }

    public function providerId(): ?Uuid
    {
        return $this->provider?->getId();
    }
}
