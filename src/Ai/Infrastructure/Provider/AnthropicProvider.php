<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure\Provider;

use App\Ai\Domain\Contract\AiProviderException;
use App\Ai\Domain\Contract\AiProviderInterface;
use App\Ai\Domain\Enum\AiTier;
use App\Ai\Domain\ValueObject\AiRequest;
use App\Ai\Domain\ValueObject\AiResponse;
use App\Integrations\Application\IntegrationConfigReader;

use function is_array;
use function is_string;
use function json_decode;
use function mb_strtolower;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Anthropic (Claude).
 *
 * No es compatible con la API de OpenAI: usa `/v1/messages`, el system prompt
 * va en un campo aparte y la autenticación es `x-api-key` con una versión de
 * API en cabecera. Por eso tiene su propia clase en vez de colgar de
 * `AbstractOpenAiCompatibleProvider`.
 */
final class AnthropicProvider implements AiProviderInterface
{
    public function __construct(
        private readonly IntegrationConfigReader $config,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function key(): string
    {
        return 'anthropic';
    }

    public function name(): string
    {
        return $this->config->labelFor($this->key());
    }

    public function tier(): AiTier
    {
        return $this->config->aiTierOf($this->key());
    }

    public function purposes(): array
    {
        return $this->config->aiPurposesOf($this->key());
    }

    public function complete(AiRequest $request): AiResponse
    {
        $settings = $this->config->settings($this->key());
        $baseUrl = trim((string) ($settings['base_url'] ?? $this->config->baseUrl($this->key()) ?? ''));
        $apiKey = $this->config->credential($this->key(), 'api_key');

        if ('' === $baseUrl) {
            throw AiProviderException::notConfigured($this->key(), 'Falta la URL base del proveedor.');
        }

        if (null === $apiKey || '' === $apiKey) {
            throw AiProviderException::notConfigured($this->key(), 'Falta la clave de API.');
        }

        $payload = [
            'model' => (string) ($settings['model'] ?? 'claude-3-5-haiku-latest'),
            'max_tokens' => (int) ($settings['max_tokens'] ?? 2048),
            'temperature' => (float) ($settings['temperature'] ?? 0.1),
            'system' => $request->systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $request->userPrompt],
            ],
        ] + $request->options;

        $startedAt = hrtime(true);

        try {
            $response = $this->httpClient->request(
                'POST',
                rtrim($baseUrl, '/').'/messages',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'x-api-key' => $apiKey,
                        'anthropic-version' => (string) ($settings['api_version'] ?? '2023-06-01'),
                    ],
                    'json' => $payload,
                    'timeout' => (float) ($settings['timeout'] ?? 30),
                ],
            );

            $statusCode = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (Throwable $exception) {
            $this->logger->error('Fallo de red al llamar al proveedor de IA.', [
                'provider' => $this->key(),
                'error' => $exception->getMessage(),
            ]);

            throw AiProviderException::requestFailed($this->key(), $exception->getMessage());
        }

        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        if ($statusCode < 200 || $statusCode >= 300) {
            $this->logger->error('El proveedor de IA ha devuelto un error.', [
                'provider' => $this->key(),
                'status' => $statusCode,
                'body' => mb_strtolower(substr($body, 0, 500)),
            ]);

            throw AiProviderException::requestFailed($this->key(), 'HTTP '.$statusCode);
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw AiProviderException::invalidResponse($this->key(), 'La respuesta no es JSON válido.');
        }

        $content = $this->extractContent($decoded);

        if (null === $content) {
            throw AiProviderException::invalidResponse($this->key(), 'La respuesta no contiene contenido.');
        }

        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $model = $decoded['model'] ?? null;

        return new AiResponse(
            content: $content,
            data: $this->extractJson($content),
            promptTokens: (int) ($usage['input_tokens'] ?? 0),
            completionTokens: (int) ($usage['output_tokens'] ?? 0),
            model: is_string($model) ? $model : (string) ($settings['model'] ?? 'claude-3-5-haiku-latest'),
            rawUsage: $usage,
            durationMs: $durationMs,
        );
    }

    /**
     * Anthropic devuelve el contenido como una lista de bloques de texto.
     *
     * @param array<mixed> $decoded
     */
    private function extractContent(array $decoded): ?string
    {
        $blocks = $decoded['content'] ?? null;

        if (!is_array($blocks)) {
            return null;
        }

        $text = '';

        foreach ($blocks as $block) {
            if (is_array($block) && 'text' === ($block['type'] ?? null) && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return '' === $text ? null : $text;
    }

    /**
     * @return array<mixed>
     */
    private function extractJson(string $content): array
    {
        $decoded = json_decode(trim($content), true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if (false !== $start && false !== $end && $end > $start) {
            $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
