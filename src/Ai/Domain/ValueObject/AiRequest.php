<?php

declare(strict_types=1);

namespace App\Ai\Domain\ValueObject;

/**
 * Lo que se le pide a un modelo de IA.
 *
 * Es **immutable y sin dependencias del framework**: el mismo objeto sirve
 * para llamar a un proveedor HTTP, para escribir una traza y para probar el
 * circuito sin necesidad de simular una red.
 */
final readonly class AiRequest
{
    /**
     * @param string               $systemPrompt instrucciones del sistema (rol, formato de salida, restricciones)
     * @param string               $userPrompt   la pregunta o el documento ya redactado
     * @param array<string, mixed> $options      opciones del proveedor (modelo, temperatura, max_tokens…)
     */
    public function __construct(
        public string $systemPrompt,
        public string $userPrompt,
        public array $options = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'systemPrompt' => $this->systemPrompt,
            'userPrompt' => $this->userPrompt,
            'options' => $this->options,
        ];
    }
}
