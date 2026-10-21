<?php

declare(strict_types=1);

namespace App\Processing\Application\Matching;

use function is_numeric;

/**
 * Pesos y umbrales del emparejamiento de documentos con servicios
 * (ARCHITECTURE.md §13.10, D-35).
 *
 * Son **configuración de producto**, no constantes del código: qué señales
 * pesan más y qué puntuación convierte una propuesta en certeza es una
 * decisión que cambia con el producto, no con el código. Por eso viven en
 * `config/packages/processing.yaml` y llegan aquí ya validadas.
 *
 * La tabla de pesos que fija la arquitectura es:
 *
 * | Señal              | Peso |
 * |--------------------|------|
 * | Proveedor          | +40  |
 * | Dominio remitente  | +20  |
 * | Nombre del servicio| +20  |
 * | Moneda             | +5   |
 * | Periodicidad       | +5   |
 * | Importe compatible | +5   |
 * | Remitente          | +5   |
 *
 * Con los valores por defecto el máximo es 100 y los umbrales dejan tres
 * zonas: `>= 70` alta (asociación automática), `40-69` media (revisión) y
 * `< 40` baja (servicio nuevo).
 */
final readonly class ServiceMatchWeights
{
    public function __construct(
        public int $provider = 40,
        public int $domain = 20,
        public int $name = 20,
        public int $currency = 5,
        public int $periodicity = 5,
        public int $amount = 5,
        public int $sender = 5,
        public int $highThreshold = 70,
        public int $mediumThreshold = 40,
        public float $amountTolerance = 0.10,
    ) {
    }

    /**
     * Construye los pesos a partir de la configuración plana.
     *
     * La configuración llega como un array de escalares (`D-43`): Symfony no
     * puede resolver los elementos de un array anidado, así que el bloque
     * entero se pasa a la factoría y de aquí se extrae lo que haga falta.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $int = static fn (string $key, int $default): int => is_numeric($config[$key] ?? null) ? (int) $config[$key] : $default;

        $float = static fn (string $key, float $default): float => is_numeric($config[$key] ?? null) ? (float) $config[$key] : $default;

        return new self(
            provider: $int('provider_weight', 40),
            domain: $int('domain_weight', 20),
            name: $int('name_weight', 20),
            currency: $int('currency_weight', 5),
            periodicity: $int('periodicity_weight', 5),
            amount: $int('amount_weight', 5),
            sender: $int('sender_weight', 5),
            highThreshold: $int('high_threshold', 70),
            mediumThreshold: $int('medium_threshold', 40),
            amountTolerance: $float('amount_tolerance', 0.10),
        );
    }

    public static function default(): self
    {
        return new self();
    }
}
