<?php

declare(strict_types=1);

namespace App\Integrations\Application;

use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Integrations\Domain\Entity\Integration;
use App\Integrations\Domain\Repository\IntegrationRepositoryInterface;
use App\Mailbox\Application\CredentialCipherInterface;

use function is_array;
use function is_scalar;
use function json_decode;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lectura de la configuración guardada de una integración.
 *
 * Es el **contraparte** de `IntegrationConfigWriter`: el escritor es el único
 * sitio donde una credencial está en claro y se persiste; este es el único
 * sitio donde una credencial guardada se descifra para usarse.
 *
 * Descifrar tiene un coste y un riesgo, así que se hace **una vez por uso** y
 * nunca se cachea: un valor descifrado en memoria es un valor descifrado en
 * memoria.
 */
final readonly class IntegrationConfigReader
{
    public function __construct(
        private IntegrationRepositoryInterface $repository,
        private IntegrationRegistry $registry,
        private CredentialCipherInterface $credentialCipher,
        private LoggerInterface $logger,
    ) {
    }

    public function find(string $key): ?Integration
    {
        return $this->repository->find($key);
    }

    /**
     * @return array<string, string>
     */
    public function settings(string $key): array
    {
        $integration = $this->repository->find($key);

        if (null === $integration) {
            return [];
        }

        /** @var array<string, string> $settings */
        $settings = array_filter(
            $integration->getSettings(),
            static fn (mixed $value): bool => is_scalar($value),
        );

        return $settings;
    }

    /**
     * Credenciales descifradas de una integración.
     *
     * @return array<string, string>
     */
    public function credentials(string $key): array
    {
        $integration = $this->repository->find($key);

        if (null === $integration) {
            return [];
        }

        return $this->readCredentials($integration);
    }

    /**
     * Una credencial concreta, o `null` si no está guardada.
     */
    public function credential(string $key, string $name): ?string
    {
        return $this->credentials($key)[$name] ?? null;
    }

    /**
     * ¿Está la integración activa y con credenciales?
     *
     * Es la pregunta que se hace un proveedor de IA antes de intentar nada:
     * una integración desactivada o sin clave no debe gastar un intento de red.
     */
    public function isUsable(string $key, bool $requiresCredentials = true): bool
    {
        $integration = $this->repository->find($key);

        if (null === $integration || !$integration->isEnabled()) {
            return false;
        }

        if (!$requiresCredentials) {
            return true;
        }

        return $integration->hasCredentials();
    }

    /**
     * Etiqueta legible de una integración del catálogo.
     */
    public function labelFor(string $key): string
    {
        $definition = $this->registry->find($key);

        return null !== $definition ? $definition->label : $key;
    }

    /**
     * URL base guardada de una integración.
     */
    public function baseUrl(string $key): ?string
    {
        $baseUrl = $this->repository->find($key)?->getBaseUrl();

        return (null === $baseUrl || '' === $baseUrl) ? null : $baseUrl;
    }

    /**
     * Ajustos guardados de una integración, con los valores por defecto del
     * catálogo aplicados para lo que no se haya configurado.
     *
     * Los ajustes **no** son secretos: son el modelo, la temperatura, el
     * número máximo de tokens… Se guardan en claro a propósito, para que el
     * panel pueda mostrarlos y el usuario pueda entender qué está pasando.
     *
     * @return array<string, string>
     */
    public function resolvedSettings(string $key): array
    {
        $definition = $this->registry->find($key);
        $settings = [];

        foreach (null !== $definition ? $definition->settingsFields : [] as $field) {
            $default = $field->default;

            if (null !== $default) {
                $settings[$field->name] = $default;
            }
        }

        return array_merge($settings, $this->settings($key));
    }

    /**
     * Nivel de IA de un proveedor del catálogo.
     */
    public function aiTierOf(string $key): AiTier
    {
        return $this->registry->aiTierOf($key);
    }

    /**
     * Necesidades que puede cubrir un proveedor del catálogo.
     *
     * @return list<AiPurpose>
     */
    public function aiPurposesOf(string $key): array
    {
        return $this->registry->aiPurposesOf($key);
    }

    /**
     * @return array<string, string>
     */
    private function readCredentials(Integration $integration): array
    {
        $encrypted = $integration->getCredentialsEncrypted();

        if (null === $encrypted || '' === $encrypted) {
            return [];
        }

        try {
            $decoded = json_decode($this->credentialCipher->decrypt($encrypted), true);
        } catch (Throwable $exception) {
            $this->logger->error('No se han podido descifrar las credenciales de la integración.', [
                'integration' => $integration->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        return is_array($decoded) ? array_filter($decoded, is_string(...)) : [];
    }
}
