<?php

declare(strict_types=1);

namespace App\Ai\Domain\Contract;

use App\Ai\Domain\Enum\AiPurpose;

/**
 * Selecciona el proveedor de IA que corresponde a una necesidad.
 *
 * Es un puerto y no una clase concreta porque la decisión depende de la
 * configuración (el panel de integraciones), del propósito de la llamada y del
 * nivel de escalado. La implementación de infrastructure lee la configuración;
 * el proceso de extracción solo pregunta «dame el proveedor para esto».
 */
interface AiProviderSelectorInterface
{
    /**
     * Proveedor activo para una necesidad concreta.
     *
     * @throws AiProviderException si no hay ningún proveedor configurado para esa necesidad
     */
    public function forPurpose(AiPurpose $purpose): AiProviderInterface;

    /**
     * Proveedor de nivel avanzado para escalar un caso difícil.
     *
     * @throws AiProviderException si no hay ningún proveedor avanzado configurado
     */
    public function advanced(): AiProviderInterface;

    /**
     * ¿Hay algún proveedor disponible para esta necesidad?
     */
    public function has(AiPurpose $purpose): bool;

    /**
     * Identificadores de los proveedores configurados, para las trazas.
     *
     * @return list<string>
     */
    public function configuredKeys(): array;
}
