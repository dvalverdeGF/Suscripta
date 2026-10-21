<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure\Provider;

/**
 * OpenAI: el nivel avanzado por defecto.
 */
final class OpenAiProvider extends AbstractOpenAiCompatibleProvider
{
    public function key(): string
    {
        return 'openai';
    }

    protected function defaultModel(): string
    {
        return 'gpt-4o-mini';
    }
}
