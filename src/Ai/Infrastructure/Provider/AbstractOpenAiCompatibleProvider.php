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
use function preg_match;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

use function trim;

/**
 * Cliente HTTP de la API de chat compatible con OpenAI.
 *
 * Un solo adaptador cubre Groq, OpenAI y Ollama: los tres exponen la misma
 * familia de API (`POST /chat/completions`). Añadir Mistral o vLLM es una
 * entrada más en el catálogo de integraciones, no otra clase.
 *
 * ## Por qué la URL se parte
 *
 * La URL base se guarda en la integración y el camino (`/chat/completions`) se
 * añade aquí. Guardar la URL completa en el panel dejaría al usuario
 * escribiendo la ruta a mano, y una errata («/v1/chat/completion») produce un
 * 404 difícil de interpretar.
 */
abstract class AbstractOpenAiCompatibleProvider implements AiProviderInterface
{
    public function __construct(
        protected readonly IntegrationConfigReader $config,
        protected readonly HttpClientInterface $httpClient,
        protected readonly LoggerInterface $logger,
    ) {
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
        $baseUrl = $this->baseUrl($settings);
        $apiKey = $this->config->credential($this->key(), 'api_key');

        $payload = [
            'model' => (string) ($settings['model'] ?? $this->defaultModel()),
            'messages' => [
                ['role' => 'system', 'content' => $request->systemPrompt],
                ['role' => 'user', 'content' => $request->userPrompt],
            ],
            'temperature' => (float) ($settings['temperature'] ?? 0.1),
        ] + $request->options;

        if (null !== $apiKey && '' !== $apiKey) {
            $payload['stream'] = false;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if (null !== $apiKey && '' !== $apiKey) {
            $headers['Authorization'] = 'Bearer '.$apiKey;
        }

        $startedAt = hrtime(true);

        try {
            $response = $this->httpClient->request(
                'POST',
                rtrim($baseUrl, '/').'/chat/completions',
                [
                    'headers' => $headers,
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

        return $this->buildResponse($content, $decoded, $durationMs);
    }

    /**
     * Modelo por defecto del proveedor, si la integración no define otro.
     */
    abstract protected function defaultModel(): string;

    /**
     * Extrae el contenido textual de la respuesta.
     *
     * La API estándar usa `choices[0].message.content`; Ollama devuelve
     * `message.content` en la raíz. Se aceptan ambos para no tener que
     * especializar el cliente por proveedor.
     *
     * @param array<mixed> $decoded
     */
    protected function extractContent(array $decoded): ?string
    {
        $choices = $decoded['choices'] ?? null;

        if (is_array($choices) && isset($choices[0]) && is_array($choices[0])) {
            $message = $choices[0]['message'] ?? null;

            if (is_array($message) && isset($message['content']) && is_string($message['content'])) {
                return $message['content'];
            }

            if (isset($choices[0]['text']) && is_string($choices[0]['text'])) {
                return $choices[0]['text'];
            }
        }

        $message = $decoded['message'] ?? null;

        if (is_array($message) && isset($message['content']) && is_string($message['content'])) {
            return $message['content'];
        }

        return null;
    }

    /**
     * Construye la respuesta normalizada a partir del JSON crudo.
     *
     * @param array<mixed> $decoded
     */
    protected function buildResponse(string $content, array $decoded, int $durationMs): AiResponse
    {
        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $promptTokens = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);

        $model = $decoded['model'] ?? null;

        return new AiResponse(
            content: $content,
            data: $this->extractJson($content),
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            model: is_string($model) ? $model : $this->defaultModel(),
            rawUsage: $usage,
            durationMs: $durationMs,
        );
    }

    /**
     * Intenta extraer un objeto JSON del contenido.
     *
     * Los modelos devuelven a veces el JSON envuelto en vallas
     * ```` ```json … ``` ````. Se toleran porque el formato de la respuesta no
     * es algo que se pueda imponer al proveedor.
     *
     * @return array<mixed>
     */
    protected function extractJson(string $content): array
    {
        $candidates = [$content];

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $content, $matches)) {
            $candidates[] = $matches[1];
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if (false !== $start && false !== $end && $end > $start) {
            $candidates[] = substr($content, $start, $end - $start + 1);
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode(trim($candidate), true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * URL base efectiva de la llamada.
     *
     * @param array<string, mixed> $settings
     */
    protected function baseUrl(array $settings): string
    {
        $baseUrl = (string) ($settings['base_url'] ?? $this->config->baseUrl($this->key()) ?? '');

        if ('' === $baseUrl) {
            throw AiProviderException::notConfigured($this->key(), 'Falta la URL base del proveedor.');
        }

        return $baseUrl;
    }
}
