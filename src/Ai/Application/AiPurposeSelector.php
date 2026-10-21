<?php

declare(strict_types=1);

namespace App\Ai\Application;

use App\Ai\Domain\Contract\AiProviderException;
use App\Ai\Domain\Contract\AiProviderInterface;
use App\Ai\Domain\Contract\AiProviderRegistryInterface;
use App\Ai\Domain\Contract\AiProviderSelectorInterface;
use App\Ai\Domain\Enum\AiPurpose;
use App\Integrations\Application\IntegrationConfigReader;
use App\Shared\Application\OrganizationSettingsReader;

use function array_key_exists;
use function array_keys;
use function is_array;
use function is_string;

use Psr\Log\LoggerInterface;

/**
 * Elige el proveedor de IA para cada necesidad.
 *
 * ## La elección es por organización, no global
 *
 * Cada organización decide qué proveedor usa para cada necesidad, y esa
 * decisión se guarda en los **ajustes de la organización**
 * (`Organization.settings`), no en una tabla global. La razón es práctica: en
 * un SaaS, el cliente que paga por las llamadas de IA es quien decide con qué
 * proveedor se le cobran, y una configuración global obligaría a todos a usar
 * el mismo.
 *
 * El formato guardado es:
 *
 * ```json
 * {
 *   "ai.providers": { "extraction": "groq", "advanced_extraction": "openai" }
 * }
 * ```
 *
 * ## La IA está desactivada por defecto (D-08)
 *
 * Si no hay elección guardada, **no hay proveedor**. El sistema sigue
 * funcionando con extracción determinista; la IA solo entra cuando alguien la
 * activa explícitamente. Por eso `has()` devuelve `false` y el pipeline
 * degrada a `REQUIRES_REVIEW` en lugar de fallar.
 */
final readonly class AiPurposeSelector implements AiProviderSelectorInterface
{
    /**
     * Clave dentro de `Organization.settings` donde se guarda la elección.
     */
    public const string SETTINGS_KEY = 'ai.providers';

    /**
     * Clave dentro de `Organization.settings` que activa la IA.
     *
     * Existe porque "tener un proveedor configurado" y "querer que se use" no
     * son lo mismo: un operador puede dejar la clave puesta para probar y
     * apagarla sin borrarla.
     */
    public const string ENABLED_KEY = 'ai.enabled';

    public function __construct(
        private IntegrationConfigReader $config,
        private AiProviderRegistryInterface $providers,
        private OrganizationSettingsReader $organizationSettings,
        private LoggerInterface $logger,
    ) {
    }

    public function forPurpose(AiPurpose $purpose): AiProviderInterface
    {
        $provider = $this->resolve($purpose);

        if (null === $provider) {
            throw AiProviderException::notAvailableForPurpose($purpose->value);
        }

        return $provider;
    }

    public function advanced(): AiProviderInterface
    {
        $provider = $this->resolve(AiPurpose::ADVANCED_EXTRACTION);

        if (null === $provider) {
            throw AiProviderException::noAdvancedProvider();
        }

        return $provider;
    }

    public function has(AiPurpose $purpose): bool
    {
        return null !== $this->resolve($purpose);
    }

    public function configuredKeys(): array
    {
        $keys = [];

        foreach (AiPurpose::all() as $purpose) {
            $provider = $this->resolve($purpose);

            if (null !== $provider) {
                $keys[$provider->key()] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Proveedor elegido para una necesidad, o `null` si no hay ninguno.
     */
    public function resolve(AiPurpose $purpose): ?AiProviderInterface
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $choices = $this->choices();
        $key = $choices[$purpose->value] ?? null;

        if (!is_string($key) || '' === $key) {
            return null;
        }

        // La elección solo vale si el proveedor está **configurado**: tener el
        // nombre escrito en los ajustes no basta para que exista una clave
        // válida con la que llamar.
        if (!$this->config->isUsable($key, $this->requiresCredentials($key))) {
            $this->logger->debug('Proveedor de IA elegido sin configuración válida.', [
                'purpose' => $purpose->value,
                'provider' => $key,
            ]);

            return null;
        }

        return $this->providerFor($key);
    }

    /**
     * ¿Está la IA activada para la organization activa?
     */
    public function isEnabled(): bool
    {
        return true === $this->organizationSettings->get(self::ENABLED_KEY, false);
    }

    /**
     * Elección guardada, indexada por necesidad.
     *
     * @return array<string, string>
     */
    public function choices(): array
    {
        $raw = $this->organizationSettings->get(self::SETTINGS_KEY, []);

        if (!is_array($raw)) {
            return [];
        }

        $choices = [];

        foreach ($raw as $purpose => $key) {
            if (is_string($key) && '' !== $key) {
                $choices[(string) $purpose] = $key;
            }
        }

        return $choices;
    }

    /**
     * Guardar la elección de proveedor por necesidad.
     *
     * @param array<string, string> $choices
     */
    public function saveChoices(array $choices): void
    {
        $normalized = [];
        $validPurposes = array_flip(self::purposes());

        foreach ($choices as $purpose => $key) {
            if (!array_key_exists($purpose, $validPurposes)) {
                continue;
            }

            $normalized[$purpose] = $key;
        }

        $this->organizationSettings->set(self::SETTINGS_KEY, $normalized);
    }

    /**
     * Activar o desactivar la IA para la organización.
     */
    public function setEnabled(bool $enabled): void
    {
        $this->organizationSettings->set(self::ENABLED_KEY, $enabled);
    }

    /**
     * @return list<string>
     */
    private static function purposes(): array
    {
        $purposes = [];

        foreach (AiPurpose::all() as $purpose) {
            $purposes[] = $purpose->value;
        }

        return $purposes;
    }

    /**
     * Resuelve el proveedor concreto a partir de la clave elegida.
     *
     * Se resuelve por el **registro de IA** —no por el de integraciones— para
     * que la lista de proveedores disponibles y la de proveedores elegibles no
     * puedan divergir: el registro de IA se construye con las clases
     * etiquetadas, que son las únicas que el motor sabe usar.
     */
    private function providerFor(string $key): ?AiProviderInterface
    {
        return $this->providers->find($key);
    }

    /**
     * Ollama no necesita credenciales: es un servidor local.
     */
    private function requiresCredentials(string $key): bool
    {
        return 'ollama' !== $key;
    }
}
