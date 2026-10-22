<?php

declare(strict_types=1);

namespace App\Shared\Application\Entitlements;

use App\Identity\Domain\Enum\OrganizationPlan;
use App\Shared\Domain\Exception\InvalidArgumentException;

use function array_key_exists;
use function in_array;
use function is_bool;
use function is_int;
use function sprintf;

/**
 * Límites de cada plan (config/packages/entitlements.yaml).
 *
 * ## Por qué configuración y no constantes
 *
 * Los números de un plan son una decisión de negocio que cambia: subir el
 * precio, dar margen a un cliente, compensar una incidencia. Cambiar un
 * límite no puede exigir un despliegue, y sobre todo no puede dejar a la
 * aplicación con un valor que no es el que se cree (§3 del D-43: el bloque se
 * consume entero).
 *
 * ## El bloque es autodescriptivo
 *
 * La configuración declara **qué capacidades existen** y de qué tipo es cada
 * una:
 *
 * - `limit`: un número. `null` significa «sin límite» y `0` significa «esta
 *   capacidad no está disponible en el plan».
 * - `flag`: un interruptor. Sirve para lo que no se cuenta sino que se tiene
 *   o no se tiene (por ejemplo, la inteligencia artificial).
 *
 * ## Falla al arrancar, no en producción
 *
 * Al construirse, esta clase comprueba que **todos los planes declaran todas
 * las capacidades**. Si alguien añade una capacidad al código y olvida
 * meterla en la configuración, el contenedor no llega a construirse: es un
 * error de despliegue, visible y temprano, y no un límite que desaparece en
 * silencio.
 */
final readonly class EntitlementSettings
{
    public const TYPE_LIMIT = 'limit';
    public const TYPE_FLAG = 'flag';

    /**
     * Capacidades conocidas por el código.
     *
     * Se comparan con las de la configuración para detectar typos: un
     * `servicios` en el YAML que el código no conoce es un error, no una
     * capacidad que se ignora en silencio.
     *
     * @var list<string>
     */
    private const KNOWN_CAPABILITIES = [
        Capability::SERVICES,
        Capability::EMAIL_ACCOUNTS,
        Capability::DOCUMENTS,
        Capability::AI,
        Capability::AI_BUDGET_MINOR,
    ];

    /**
     * @param array<string, array{label: string, type: string, unit: string|null}>      $capabilities
     * @param array<string, array{label: string, limits: array<string, int|bool|null>}> $plans
     */
    private function __construct(
        private array $capabilities,
        private array $plans,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        /** @var array<string, mixed> $rawCapabilities */
        $rawCapabilities = (array) ($config['capabilities'] ?? []);
        /** @var array<string, mixed> $rawPlans */
        $rawPlans = (array) ($config['plans'] ?? []);

        if ([] === $rawCapabilities) {
            throw new InvalidArgumentException('La configuración de entitlements no declara ninguna capacidad.');
        }

        if ([] === $rawPlans) {
            throw new InvalidArgumentException('La configuración de entitlements no declara ningún plan.');
        }

        $capabilities = self::parseCapabilities($rawCapabilities);
        $plans = self::parsePlans($rawPlans, $capabilities);

        return new self($capabilities, $plans);
    }

    /**
     * Metadatos de una capacidad: etiqueta, tipo y unidad.
     *
     * @return array{label: string, type: string, unit: string|null}|null
     */
    public function capability(string $capability): ?array
    {
        return $this->capabilities[$capability] ?? null;
    }

    /**
     * Etiqueta legible de una capacidad.
     */
    public function labelFor(string $capability): string
    {
        return $this->capabilities[$capability]['label'] ?? $capability;
    }

    /**
     * Unidad en la que se cuenta una capacidad («servicios», «documentos»…).
     */
    public function unitFor(string $capability): ?string
    {
        return $this->capabilities[$capability]['unit'] ?? null;
    }

    /**
     * Todas las capacidades declaradas, en el orden de la configuración.
     *
     * @return list<string>
     */
    public function capabilityNames(): array
    {
        return array_keys($this->capabilities);
    }

    /**
     * Todos los planes declarados, en el orden de la configuración.
     *
     * @return list<string>
     */
    public function planNames(): array
    {
        return array_keys($this->plans);
    }

    /**
     * Etiqueta legible de un plan.
     */
    public function planLabel(OrganizationPlan $plan): string
    {
        return $this->plans[$plan->value]['label'] ?? $plan->label();
    }

    /**
     * ¿La configuración conoce este plan?
     */
    public function hasPlan(OrganizationPlan $plan): bool
    {
        return array_key_exists($plan->value, $this->plans);
    }

    /**
     * Valor de una capacidad para un plan.
     *
     * `int` para las capacidades de tipo límite, `bool` para las de tipo
     * interruptor y `null` para un límite sin techo.
     */
    public function valueFor(OrganizationPlan $plan, string $capability): int|bool|null
    {
        return $this->plans[$plan->value]['limits'][$capability] ?? null;
    }

    /**
     * Límite numérico de una capacidad para un plan (`null` = sin límite).
     */
    public function limitFor(OrganizationPlan $plan, string $capability): ?int
    {
        $value = $this->valueFor($plan, $capability);

        if (is_bool($value) || null === $value) {
            return null;
        }

        return $value;
    }

    /**
     * ¿Un plan ofrece esta capacidad?
     */
    public function allows(OrganizationPlan $plan, string $capability): bool
    {
        $value = $this->valueFor($plan, $capability);

        if (is_bool($value)) {
            return $value;
        }

        if (null === $value) {
            return true;
        }

        return $value > 0;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, array{label: string, type: string, unit: string|null}>
     */
    private static function parseCapabilities(array $raw): array
    {
        $capabilities = [];

        foreach ($raw as $name => $definition) {
            $name = (string) $name;

            if (!in_array($name, self::KNOWN_CAPABILITIES, true)) {
                throw new InvalidArgumentException(sprintf('La configuración de entitlements declara la capacidad "%s", que el código no conoce.', $name));
            }

            /** @var array<string, mixed> $definition */
            $definition = (array) $definition;
            $type = (string) ($definition['type'] ?? '');

            if (!in_array($type, [self::TYPE_LIMIT, self::TYPE_FLAG], true)) {
                throw new InvalidArgumentException(sprintf('La capacidad "%s" debe ser de tipo "%s" o "%s".', $name, self::TYPE_LIMIT, self::TYPE_FLAG));
            }

            $capabilities[$name] = [
                'label' => (string) ($definition['label'] ?? $name),
                'type' => $type,
                'unit' => isset($definition['unit']) ? (string) $definition['unit'] : null,
            ];
        }

        foreach (self::KNOWN_CAPABILITIES as $known) {
            if (!array_key_exists($known, $capabilities)) {
                throw new InvalidArgumentException(sprintf('La configuración de entitlements no declara la capacidad "%s".', $known));
            }
        }

        return $capabilities;
    }

    /**
     * @param array<string, mixed>                                                 $raw
     * @param array<string, array{label: string, type: string, unit: string|null}> $capabilities
     *
     * @return array<string, array{label: string, limits: array<string, int|bool|null>}>
     */
    private static function parsePlans(array $raw, array $capabilities): array
    {
        $plans = [];

        foreach ($raw as $name => $definition) {
            $name = (string) $name;

            if (null === OrganizationPlan::tryFrom($name)) {
                throw new InvalidArgumentException(sprintf('La configuración de entitlements declara el plan "%s", que no existe en el código.', $name));
            }

            /** @var array<string, mixed> $definition */
            $definition = (array) $definition;
            /** @var array<string, mixed> $limits */
            $limits = (array) ($definition['limits'] ?? $definition);

            $parsed = [];

            foreach ($capabilities as $capability => $meta) {
                if (!array_key_exists($capability, $limits)) {
                    throw new InvalidArgumentException(sprintf('El plan "%s" no declara la capacidad "%s".', $name, $capability));
                }

                $value = $limits[$capability];

                if (self::TYPE_FLAG === $meta['type']) {
                    if (!is_bool($value)) {
                        throw new InvalidArgumentException(sprintf('La capacidad "%s" del plan "%s" debe ser un interruptor.', $capability, $name));
                    }

                    $parsed[$capability] = $value;

                    continue;
                }

                if (null !== $value && !is_int($value)) {
                    throw new InvalidArgumentException(sprintf('La capacidad "%s" del plan "%s" debe ser un número entero o null.', $capability, $name));
                }

                $parsed[$capability] = $value;
            }

            $plans[$name] = [
                'label' => (string) ($definition['label'] ?? $name),
                'limits' => $parsed,
            ];
        }

        return $plans;
    }
}
