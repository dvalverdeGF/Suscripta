<?php

declare(strict_types=1);

namespace App\Discovery\Application\Change;

use function is_numeric;

/**
 * Parámetros de la detección de cambios (config/packages/discovery.yaml).
 *
 * Todos son **umbrales de producto**, no constantes técnicas: cuántos días se
 * espera un cobro antes de sospechar que no va a llegar, cuánto tiempo puede
 * un servicio no dar señales antes de que se sugiera cancelarlo, y
 * cuánto tienen que parecerse dos servicios para sospechar que son el mismo.
 *
 * Viven en la configuración y no en el código porque cambiarlos cambia lo que
 * el usuario ve, y eso se decide con el producto al lado, no con el
 * desplegador.
 */
final readonly class ChangeSettings
{
    public function __construct(
        /**
         * Días de margen tras el cobro esperado antes de sospechar que la
         * factura no ha llegado. El margen absorbe los días que el proveedor
         * se retrasa.
         */
        public int $missingChargeGraceDays = 7,
        /**
         * Días sin ninguna señal (documento, factura o evento) antes de que se
         * sugiera cancelar el servicio.
         */
        public int $dormantServiceDays = 180,
        /**
         * Tolerancia, en tanto por uno, al comparar importes. Dos servicios
         * con importes «prácticamente iguales» son el mismo servicio.
         */
        public float $duplicateAmountTolerance = 0.02,
        /**
         * Similitud mínima, en porcentaje, entre los nombres de dos servicios
         * para sospechar que son el mismo.
         */
        public int $duplicateNameSimilarity = 85,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            missingChargeGraceDays: self::int($config, 'missing_charge_grace_days', 7),
            dormantServiceDays: self::int($config, 'dormant_service_days', 180),
            duplicateAmountTolerance: self::float($config, 'duplicate_amount_tolerance', 0.02),
            duplicateNameSimilarity: self::int($config, 'duplicate_name_similarity', 85),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function int(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function float(array $config, string $key, float $default): float
    {
        $value = $config[$key] ?? null;

        return is_numeric($value) ? (float) $value : $default;
    }
}
