<?php

declare(strict_types=1);

namespace App\Ai\Domain\Contract;

use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Ai\Domain\ValueObject\AiRequest;
use App\Ai\Domain\ValueObject\AiResponse;

/**
 * Un proveedor de IA concreto (ARCHITECTURE.md §13.8).
 *
 * La interfaz es deliberadamente estrecha —una llamada, una respuesta— para que
 * añadir un proveedor sea escribir una clase y registrarla, no tocar el
 * proceso de extracción. Eliges el proveedor por necesidad desde el panel de
 * integraciones, así que varios proveedores pueden estar configurados a la vez
 * y solo uno está activo para cada tarea.
 */
interface AiProviderInterface
{
    /**
     * Identificador estable del proveedor (`groq`, `openai`, `anthropic`…).
     * Es la clave con la que se guardan sus credenciales.
     */
    public function key(): string;

    /**
     * Nombre legible para la interfaz.
     */
    public function name(): string;

    /**
     * Qué nivel de IA encarna este proveedor: económico o avanzado.
     */
    public function tier(): AiTier;

    /**
     * Para qué necesidades sirve. Un proveedor de un solo nivel (por ejemplo,
     * un modelo local) puede no declarar ninguno y así ser elegible solo por
     * configuración manual.
     *
     * @return list<AiPurpose>
     */
    public function purposes(): array;

    /**
     * Ejecuta la llamada.
     *
     * @throws AiProviderException cuando el proveedor falla o no está configurado
     */
    public function complete(AiRequest $request): AiResponse;
}
