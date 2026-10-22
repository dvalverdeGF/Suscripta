<?php

declare(strict_types=1);

namespace App\Tests\Support\Entitlements;

use App\Shared\Application\Entitlements\QuotaCheckerInterface;
use App\Shared\Domain\Exception\QuotaExceededException;

/**
 * Doble del comprobador de cuotas para las pruebas (Fase 17).
 *
 * Por defecto **permite todo**: la mayoría de las pruebas no van de cuotas
 * y no tienen que enterarse de ellas. Las que sí van, dicen qué capacidades
 * están bloqueadas y comprueban que la operación se para.
 */
final class InMemoryQuotaChecker implements QuotaCheckerInterface
{
    /**
     * @param array<string, int> $limits límite por capacidad; `0` = bloqueada
     */
    public function __construct(
        private readonly array $limits = [],
    ) {
    }

    /**
     * Un plan sin límites: todo permitido.
     */
    public static function allowingEverything(): self
    {
        return new self();
    }

    /**
     * Un plan con un límite por capacidad.
     *
     * @param array<string, int> $limits
     */
    public static function withLimits(array $limits): self
    {
        return new self($limits);
    }

    public function allows(string $capability): bool
    {
        $limit = $this->limits[$capability] ?? null;

        return null === $limit || $limit > 0;
    }

    public function assertWithinQuota(string $capability): void
    {
        if ($this->allows($capability)) {
            return;
        }

        throw new QuotaExceededException(capability: $capability, label: $capability, usage: 0, limit: $this->limits[$capability] ?? 0, planLabel: 'Prueba');
    }
}
