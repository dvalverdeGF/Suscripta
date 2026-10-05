<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;

/**
 * Rango de fechas semiabierto [from, to). `to` nulo significa "sin fin".
 */
final readonly class DateRange
{
    public function __construct(
        public DateTimeImmutable $from,
        public ?DateTimeImmutable $to = null,
    ) {
        if (null !== $to && $to < $from) {
            throw new InvalidArgumentException('El fin del rango no puede ser anterior al inicio.');
        }
    }

    public static function startingAt(DateTimeImmutable $from): self
    {
        return new self($from, null);
    }

    public static function between(DateTimeImmutable $from, DateTimeImmutable $to): self
    {
        return new self($from, $to);
    }

    public function isOpenEnded(): bool
    {
        return null === $this->to;
    }

    public function contains(DateTimeImmutable $moment): bool
    {
        if ($moment < $this->from) {
            return false;
        }

        return null === $this->to || $moment < $this->to;
    }

    public function overlaps(self $other): bool
    {
        $thisEndsAfterOtherStarts = null === $this->to || $this->to > $other->from;
        $otherEndsAfterThisStarts = null === $other->to || $other->to > $this->from;

        return $thisEndsAfterOtherStarts && $otherEndsAfterThisStarts;
    }

    public function days(): ?int
    {
        if (null === $this->to) {
            return null;
        }

        return (int) $this->from->diff($this->to)->days;
    }
}
