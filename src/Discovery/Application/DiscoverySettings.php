<?php

declare(strict_types=1);

namespace App\Discovery\Application;

use DateTimeImmutable;

use function sprintf;

/**
 * Parámetros de la bandeja de descubrimientos (config/packages/discovery.yaml).
 *
 * Son política de producto, no constantes: cuánto tiempo vive una propuesta y
 * cuánto se recuerda que el usuario la descartó.
 */
final readonly class DiscoverySettings
{
    public function __construct(
        public int $expiryDays = 90,
        public int $ignoreSuppressionDays = 90,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            expiryDays: (int) ($config['expiry_days'] ?? 90),
            ignoreSuppressionDays: (int) ($config['ignore_suppression_days'] ?? 90),
        );
    }

    /**
     * Desde cuándo una propuesta ignorada sigue bloqueando nuevas propuestas
     * con la misma clave.
     */
    public function ignoredSince(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d days', $this->ignoreSuppressionDays));
    }

    /**
     * Fecha límite de la bandeja: lo anterior ha caducado.
     */
    public function expiryThreshold(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d days', $this->expiryDays));
    }
}
