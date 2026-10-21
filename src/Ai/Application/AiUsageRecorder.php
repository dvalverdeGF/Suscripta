<?php

declare(strict_types=1);

namespace App\Ai\Application;

use App\Ai\Domain\Contract\AiProviderInterface;
use App\Ai\Domain\Entity\AiUsage;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Ai\Domain\Repository\AiUsageRepositoryInterface;
use App\Ai\Domain\ValueObject\AiRequest;
use App\Ai\Domain\ValueObject\AiResponse;
use App\Integrations\Application\IntegrationConfigReader;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use Psr\Log\LoggerInterface;

use function sprintf;

use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Registra cada llamada a un modelo de IA (D-36).
 *
 * ## Por qué un decorador y no un registro dentro del proveedor
 *
 * El registro tiene que saber **tres** cosas que el proveedor no sabe: qué
 * necesidad motivó la llamada, qué entidad la provocó y si la organización
 * tiene dinero. El proveedor solo sabe responder. Por eso el registro se
 * envuelve **por fuera** de la llamada: es un decorador que sabe qué se pidió
 * y luego anota lo que volvió.
 *
 * ## Se registra también el fallo
 *
 * Un fallo consume tokens igual que un acierto (casi siempre más, porque el
 * modelo suele generar una respuesta larga antes de que la validación la
 * rechace). Ocultar los fallos daría una cuenta incompleta y, peor, daría la
 * falsa impresión de que todo va bien.
 *
 * ## El registro no rompe la extracción
 *
 * Si la persistencia del uso falla, la extracción **no** se pierde: se anota
 * el error en el log y se sigue. Perder una factura por no poder escribir una
 * traza de coste sería un error peor que el propio.
 */
final readonly class AiUsageRecorder
{
    public function __construct(
        private AiUsageRepositoryInterface $usageRepository,
        private IntegrationConfigReader $config,
        private AiPriceCatalog $prices,
        private TenantContext $tenantContext,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Ejecuta la llamada y registra el uso.
     *
     * @throws \App\Ai\Domain\Contract\AiProviderException si el proveedor falla
     */
    public function record(
        AiProviderInterface $provider,
        AiPurpose $purpose,
        callable $call,
        ?string $entityType = null,
        ?Uuid $entityId = null,
    ): AiResponse {
        $startedAt = hrtime(true);

        try {
            $response = $call();
        } catch (Throwable $exception) {
            $this->persist($provider, $purpose, null, $this->elapsedMs($startedAt), false, $entityType, $entityId);

            throw $exception;
        }

        $this->persist($provider, $purpose, $response, $this->elapsedMs($startedAt), true, $entityType, $entityId);

        return $response;
    }

    /**
     * Escribe una fila de uso.
     *
     * Si no hay organización activa no se escribe nada: una llamada sin
     * organización es una llamada que no se puede atribuir a ningún cliente, y
     * un coste sin atribuir no sirve para nada.
     */
    private function persist(
        AiProviderInterface $provider,
        AiPurpose $purpose,
        ?AiResponse $response,
        int $durationMs,
        bool $successful,
        ?string $entityType,
        ?Uuid $entityId,
    ): void {
        $organizationId = $this->tenantContext->getOrganizationId();

        if (null === $organizationId) {
            return;
        }

        try {
            $price = $this->prices->forProvider($provider->key(), $this->config->settings($provider->key()));

            $model = '';
            $promptTokens = 0;
            $completionTokens = 0;
            $costMinor = 0;

            if (null !== $response) {
                $model = $response->model;
                $promptTokens = $response->promptTokens;
                $completionTokens = $response->completionTokens;
                $costMinor = $price->costMinor($promptTokens, $completionTokens);
            }

            $this->usageRepository->save(new AiUsage(
                organizationId: $organizationId,
                providerKey: $provider->key(),
                purpose: $purpose,
                tier: $provider->tier(),
                model: $model,
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                costMinor: $costMinor,
                successful: $successful,
                durationMs: $durationMs,
                entityType: $entityType,
                entityId: $entityId,
                createdAt: $this->clock->now(),
            ));
        } catch (Throwable $exception) {
            // La traza de coste es importante, pero no tanto como la factura.
            $this->logger->error('No se ha podido registrar el uso de IA.', [
                'provider' => $provider->key(),
                'purpose' => $purpose->value,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Nivel de IA de un proveedor, para la traza.
     */
    public function tierOf(AiProviderInterface $provider): AiTier
    {
        return $provider->tier();
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * Petición que se manda al modelo, solo para dejar constancia del tipo.
     */
    public function describeRequest(AiRequest $request): string
    {
        return sprintf('%d/%d', mb_strlen($request->systemPrompt), mb_strlen($request->userPrompt));
    }
}
