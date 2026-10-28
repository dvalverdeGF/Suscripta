<?php

declare(strict_types=1);

namespace App\Integrations\Application;

use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;

/**
 * Catálogo de integraciones que el código sabe usar.
 *
 * ## El registro es la lista de lo que existe, no un inventario de lo guardado
 *
 * La tabla `integration` puede contener cualquier clave, pero solo las que
 * están aquí tienen **código detrás**. Una clave escrita a mano en la base de
 * datos no aparece en el panel: no se puede configurar, así que no tiene
 * sentido enseñarla como si se pudiera.
 *
 * ## Los proveedores de IA son integraciones, y llevan su nivel
 *
 * Un proveedor de IA se declara con `isAiProvider` y con su nivel (económico o
 * avanzado) en `tier`. El selector de IA elige entre los proveedores
 * configurados usando esos mismos datos, de modo que el panel de integraciones
 * y el de IA no pueden desincronizarse: no hay dos listas que mantener.
 */
final class IntegrationRegistry
{
    public const string KEY_GROQ = 'groq';
    public const string KEY_OPENAI = 'openai';
    public const string KEY_ANTHROPIC = 'anthropic';
    public const string KEY_OLLAMA = 'ollama';
    public const string KEY_STRIPE = 'stripe';

    /**
     * @var array<string, IntegrationDefinition>
     */
    private array $definitions = [];

    public function __construct()
    {
        $this->definitions = self::build();
    }

    /**
     * @return array<string, IntegrationDefinition>
     */
    private static function build(): array
    {
        $definitions = [];

        foreach (self::aiProviderDefinitions() as $definition) {
            $definitions[$definition->key] = $definition;
        }

        foreach (self::paymentDefinitions() as $definition) {
            $definitions[$definition->key] = $definition;
        }

        return $definitions;
    }

    /**
     * Proveedores de IA soportados.
     *
     * @return list<IntegrationDefinition>
     */
    private static function aiProviderDefinitions(): array
    {
        return [
            IntegrationDefinition::of(
                key: self::KEY_GROQ,
                label: 'Groq',
                help: 'Proveedor de IA económica con modelos abiertos (Llama, Mixtral). Requiere una API key de groq.com.',
                credentialFields: [
                    IntegrationField::secret(
                        name: 'api_key',
                        label: 'Clave API',
                        required: true,
                        hint: 'Se guarda cifrada y no vuelve a mostrarse.',
                    ),
                ],
                settingsFields: [
                    IntegrationField::select(
                        name: 'model',
                        label: 'Modelo',
                        choices: [
                            ['value' => 'llama-3.3-70b-versatile', 'label' => 'Llama 3.3 70B (versátil)'],
                            ['value' => 'llama-3.1-8b-instant', 'label' => 'Llama 3.1 8B (rápido)'],
                            ['value' => 'mixtral-8x7b-32768', 'label' => 'Mixtral 8x7B'],
                            ['value' => 'gemma2-9b-it', 'label' => 'Gemma 2 9B'],
                        ],
                        default: 'llama-3.3-70b-versatile',
                        hint: 'Modelo usado para las llamadas de IA económica.',
                    ),
                    IntegrationField::number(
                        name: 'max_tokens',
                        label: 'Tokens máximos de respuesta',
                        min: 256,
                        max: 32768,
                        step: 256,
                        default: '2048',
                    ),
                ],
                requiresBaseUrl: true,
                icon: ['fa-solid', 'fa-bolt'],
                isAiProvider: true,
            ),

            IntegrationDefinition::of(
                key: self::KEY_OPENAI,
                label: 'OpenAI',
                help: 'Proveedor de IA avanzada (GPT). Requiere una API key de platform.openai.com.',
                credentialFields: [
                    IntegrationField::secret(
                        name: 'api_key',
                        label: 'Clave API',
                        required: true,
                        hint: 'Se guarda cifrada y no vuelve a mostrarse.',
                    ),
                    IntegrationField::text(
                        name: 'organization',
                        label: 'Organización',
                        hint: 'Opcional. Solo para claves de una organización concreta.',
                    ),
                ],
                settingsFields: [
                    IntegrationField::select(
                        name: 'model',
                        label: 'Modelo',
                        choices: [
                            ['value' => 'gpt-4o-mini', 'label' => 'GPT-4o mini (económico)'],
                            ['value' => 'gpt-4o', 'label' => 'GPT-4o'],
                            ['value' => 'gpt-4.1', 'label' => 'GPT-4.1'],
                        ],
                        default: 'gpt-4o-mini',
                    ),
                    IntegrationField::number(
                        name: 'max_tokens',
                        label: 'Tokens máximos de respuesta',
                        min: 256,
                        max: 32768,
                        step: 256,
                        default: '2048',
                    ),
                ],
                requiresBaseUrl: true,
                icon: ['fa-solid', 'fa-robot'],
                isAiProvider: true,
            ),

            IntegrationDefinition::of(
                key: self::KEY_ANTHROPIC,
                label: 'Anthropic',
                help: 'Proveedor de IA avanzada (Claude). Requiere una API key de console.anthropic.com.',
                credentialFields: [
                    IntegrationField::secret(
                        name: 'api_key',
                        label: 'Clave API',
                        required: true,
                        hint: 'Se guarda cifrada y no vuelve a mostrarse.',
                    ),
                ],
                settingsFields: [
                    IntegrationField::select(
                        name: 'model',
                        label: 'Modelo',
                        choices: [
                            ['value' => 'claude-3-5-haiku-latest', 'label' => 'Claude 3.5 Haiku'],
                            ['value' => 'claude-3-5-sonnet-latest', 'label' => 'Claude 3.5 Sonnet'],
                            ['value' => 'claude-3-opus-latest', 'label' => 'Claude 3 Opus'],
                        ],
                        default: 'claude-3-5-haiku-latest',
                    ),
                    IntegrationField::number(
                        name: 'max_tokens',
                        label: 'Tokens máximos de respuesta',
                        min: 256,
                        max: 32768,
                        step: 256,
                        default: '2048',
                    ),
                ],
                requiresBaseUrl: true,
                icon: ['fa-solid', 'fa-robot'],
                isAiProvider: true,
            ),

            IntegrationDefinition::of(
                key: self::KEY_OLLAMA,
                label: 'Ollama (local)',
                help: 'IA autoalojada. No necesita credenciales: el modelo se descarga y se ejecuta en tu propia máquina o servidor.',
                credentialFields: [],
                settingsFields: [
                    IntegrationField::text(
                        name: 'model',
                        label: 'Modelo',
                        required: true,
                        placeholder: 'llama3.2',
                        hint: 'Nombre del modelo instalado en Ollama.',
                    ),
                    IntegrationField::number(
                        name: 'max_tokens',
                        label: 'Tokens máximos de respuesta',
                        min: 256,
                        max: 32768,
                        step: 256,
                        default: '2048',
                    ),
                ],
                requiresBaseUrl: true,
                icon: ['fa-solid', 'fa-server'],
                isAiProvider: true,
            ),
        ];
    }

    /**
     * Pasarelas de pago.
     *
     * Van aparte de los proveedores de IA porque no se eligen por necesidad:
     * hay una y cobra. Separarlas deja el selector de IA sin una opción que no
     * es una IA, que es lo que pasaría si compartieran la misma lista.
     *
     * @return list<IntegrationDefinition>
     */
    private static function paymentDefinitions(): array
    {
        return [
            IntegrationDefinition::of(
                key: self::KEY_STRIPE,
                label: 'Stripe',
                help: 'Cobra los planes de Suscripta. Requiere la clave secreta de api.stripe.com y el secreto de firma del webhook.',
                credentialFields: [
                    IntegrationField::secret(
                        name: 'secret_key',
                        label: 'Clave secreta',
                        required: true,
                        hint: 'Empieza por sk_. Se guarda cifrada y no vuelve a mostrarse.',
                    ),
                    IntegrationField::secret(
                        name: 'webhook_secret',
                        label: 'Secreto de firma del webhook',
                        required: true,
                        hint: 'Empieza por whsec_. Es lo que permite comprobar que un aviso viene de Stripe.',
                    ),
                ],
                settingsFields: [
                    IntegrationField::text(
                        name: 'currency',
                        label: 'Moneda de cobro',
                        placeholder: 'EUR',
                        hint: 'Código ISO de tres letras. Si se deja vacío se usa la del catálogo.',
                    ),
                ],
                icon: ['fa-brands', 'fa-stripe-s'],
            ),
        ];
    }

    /**
     * Todas las definiciones.
     *
     * @return list<IntegrationDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function find(string $key): ?IntegrationDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function knows(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->definitions);
    }

    /**
     * Solo los proveedores de IA.
     *
     * @return list<IntegrationDefinition>
     */
    public function aiProviders(): array
    {
        return array_values(array_filter(
            $this->definitions,
            static fn (IntegrationDefinition $d): bool => $d->isAiProvider,
        ));
    }

    /**
     * Nivel de IA de un proveedor.
     *
     * Los proveedores con modelos abiertos y los locales son **económicos**;
     * los de frontera son **avanzados**. La clasificación es por proveedor
     * porque es lo que el operador puede cambiar desde el panel sin tocar
     * código, y lo que necesita saber el escalado de nivel 6.
     */
    public function aiTierOf(string $key): AiTier
    {
        return match ($key) {
            self::KEY_OPENAI, self::KEY_ANTHROPIC => AiTier::ADVANCED,
            default => AiTier::CHEAP,
        };
    }

    /**
     * Necesidades que puede cubrir un proveedor.
     *
     * Los económicos cubren la extracción y el resumen; los avanzados, además,
     * la extracción difícil (nivel 6) y la clasificación. Un proveedor
     * avanzado puede hacer también lo de un económico: no tiene sentido
     * prohibírselo.
     *
     * @return list<AiPurpose>
     */
    public function aiPurposesOf(string $key): array
    {
        return match (true) {
            AiTier::ADVANCED === $this->aiTierOf($key) => [
                AiPurpose::EXTRACTION,
                AiPurpose::ADVANCED_EXTRACTION,
                AiPurpose::SUMMARIZATION,
                AiPurpose::CLASSIFICATION,
            ],
            default => [
                AiPurpose::EXTRACTION,
                AiPurpose::SUMMARIZATION,
                AiPurpose::CLASSIFICATION,
            ],
        };
    }
}
