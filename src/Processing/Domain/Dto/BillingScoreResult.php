<?php

declare(strict_types=1);

namespace App\Processing\Domain\Dto;

use function sprintf;

/**
 * Resultado del filtro determinista (ARCHITECTURE.md §13.4).
 *
 * `score` está acotado a 0..100 y `reasons` explica cómo se ha llegado a él.
 * `isCandidate()` es la única decisión que el pipeline toma con este objeto:
 * por debajo del umbral el mensaje se descarta **sin descargar su contenido**.
 */
final readonly class BillingScoreResult
{
    /**
     * @param list<BillingSignal> $reasons
     */
    public function __construct(
        public int $score,
        public array $reasons,
        public int $threshold,
    ) {
    }

    public function isCandidate(): bool
    {
        return $this->score >= $this->threshold;
    }

    /**
     * @return list<array{signal: string, weight: int, detail?: string}>
     */
    public function reasonsAsArray(): array
    {
        return array_map(static fn (BillingSignal $signal): array => $signal->toArray(), $this->reasons);
    }

    /**
     * Explicación en una línea, para la interfaz y los logs.
     */
    public function explain(): string
    {
        if ([] === $this->reasons) {
            return sprintf('Sin señales de facturación (puntuación %d).', $this->score);
        }

        $parts = array_map(
            static fn (BillingSignal $signal): string => sprintf('%s %+d', $signal->signal, $signal->weight),
            $this->reasons,
        );

        return sprintf('Puntuación %d: %s.', $this->score, implode(', ', $parts));
    }
}
