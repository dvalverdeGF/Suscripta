<?php

declare(strict_types=1);

namespace App\Ai\Domain\Contract;

use App\Ai\Domain\Enum\AiTier;

/**
 * Registro de proveedores de IA disponibles (D-36).
 *
 * Permite saber de antemano qué proveedores están configurados, con qué
 * credenciales y a qué coste, sin llamar a ninguno. Es lo que necesita el
 * panel de integraciones para no mentir: si un proveedor no tiene credenciales
 * guardadas, aparece como «no configurado» y no como «activo».
 */
interface AiProviderRegistryInterface
{
    /**
     * @return list<AiProviderInterface>
     */
    public function all(): array;

    public function find(string $key): ?AiProviderInterface;

    public function knows(string $key): bool;

    /**
     * @return list<string>
     */
    public function keys(): array;

    /**
     * Proveedores de un nivel concreto.
     *
     * @return list<AiProviderInterface>
     */
    public function byTier(AiTier $tier): array;
}
