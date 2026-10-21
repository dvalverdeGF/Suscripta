<?php

declare(strict_types=1);

namespace App\Processing\Domain\Dto;

use App\Processing\Domain\Matching\MatchConfidence;

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
 *
 * Solo guarda el **identificador** del servicio candidato, no la entidad: este
 * DTO vive en el módulo `Processing` y no debe arrastrar la entidad de otro
 * módulo (ARCHITECTURE.md §3). El pipeline necesita saber *a quién* pertenece
 * el documento, no mutarlo.
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
        public MatchConfidence $confidence = MatchConfidence::LOW,
    ) {
    }

    public static function none(): self
    {
        return new self(0, null, [], MatchConfidence::LOW);
    }

    /**
     * Umbral de asociación automática: por encima, el sistema da el documento
     * por perteneciente a un servicio existente.
     */
    public function isHighConfidence(): bool
    {
        return MatchConfidence::HIGH === $this->confidence && null !== $this->serviceId;
    }

    /**
     * Zona intermedia: hay un candidato razonable, pero el usuario debe
     * decidir. Se materializa como `Discovery` de tipo `price_change`.
     */
    public function isMediumConfidence(): bool
    {
        return MatchConfidence::MEDIUM === $this->confidence && null !== $this->serviceId;
    }

    /**
     * Sin candidato creíble: se propone un servicio nuevo.
     */
    public function isLowConfidence(): bool
    {
        return !$this->isHighConfidence() && !$this->isMediumConfidence();
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
    public static function forService(
        Uuid $serviceId,
        int $score,
        array $reasons,
        MatchConfidence $confidence,
    ): self {
        return new self($score, $serviceId, $reasons, $confidence);
    }
}
