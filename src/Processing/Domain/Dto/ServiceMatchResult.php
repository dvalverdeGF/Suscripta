<?php

declare(strict_types=1);

namespace App\Processing\Domain\Dto;

use App\Services\Domain\Entity\Service;

use function implode;
use function sprintf;

use Symfony\Component\Uid\Uuid;

/**
 * Resultado del emparejamiento entre un documento extraído y el inventario de
 * servicios (ARCHITECTURE.md §13.10).
 *
 * El emparejamiento es **ponderado y explicable**: cada señal que ha sumado
 * puntos queda registrada para que la interfaz pueda justificar la propuesta.
 * Nunca se crea ni se modifica un `Service` a partir de este resultado sin
 * confirmación del usuario (D-35).
 */
final readonly class ServiceMatchResult
{
    /**
     * @param list<array{signal: string, weight: int, detail?: string|null}> $reasons
     */
    public function __construct(
        public int $score,
        public ?Uuid $serviceId,
        public array $reasons = [],
    ) {
    }

    public static function none(): self
    {
        return new self(0, null);
    }

    /**
     * Umbral de asociación automática: por encima, el sistema da el documento
     * por perteneciente a un servicio existente.
     */
    public function isHighConfidence(): bool
    {
        return $this->score >= 70 && null !== $this->serviceId;
    }

    /**
     * Zona intermedia: hay un candidato razonable, pero el usuario debe
     * decidir. Se materializa como `Discovery` de tipo `price_change`.
     */
    public function isMediumConfidence(): bool
    {
        return $this->score >= 40 && $this->score < 70 && null !== $this->serviceId;
    }

    /**
     * Sin candidato creíble: se propone un servicio nuevo.
     */
    public function isLowConfidence(): bool
    {
        return $this->score < 40 || null === $this->serviceId;
    }

    /**
     * @return list<array{signal: string, weight: int, detail?: string|null}>
     */
    public function reasonsAsArray(): array
    {
        return $this->reasons;
    }

    public function explain(): string
    {
        if ([] === $this->reasons) {
            return 'Sin coincidencias con los servicios registrados.';
        }

        $parts = [];

        foreach ($this->reasons as $reason) {
            $parts[] = sprintf('%s (%+d)', $reason['signal'], $reason['weight']);
        }

        return sprintf('Puntuación %d: %s', $this->score, implode(', ', $parts));
    }

    /**
     * @param list<array{signal: string, weight: int, detail?: string|null}> $reasons
     */
    public static function forService(Service $service, int $score, array $reasons): self
    {
        return new self($score, $service->getId(), $reasons);
    }
}
