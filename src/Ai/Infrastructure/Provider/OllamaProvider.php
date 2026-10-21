<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure\Provider;

/**
 * Ollama: inferencia local. No necesita credenciales, solo una URL.
 */
final class OllamaProvider extends AbstractOpenAiCompatibleProvider
{
    public function key(): string
    {
        return 'ollama';
    }

    protected function defaultModel(): string
    {
        return 'llama3.2';
    }
}
