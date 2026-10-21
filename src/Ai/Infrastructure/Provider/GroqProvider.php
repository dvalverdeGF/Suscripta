<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure\Provider;

/**
 * Groq: inferencia rápida y barata. Es el nivel económico por defecto.
 */
final class GroqProvider extends AbstractOpenAiCompatibleProvider
{
    public function key(): string
    {
        return 'groq';
    }

    protected function defaultModel(): string
    {
        return 'llama-3.3-70b-versatile';
    }
}
