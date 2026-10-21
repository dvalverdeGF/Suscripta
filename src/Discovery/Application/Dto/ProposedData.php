<?php

declare(strict_types=1);

namespace App\Discovery\Application\Dto;

use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;

use function is_numeric;
use function is_string;

use Throwable;

use function trim;

/**
 * Lectura tipada de lo que el pipeline propuso.
 *
 * `Discovery.proposedData` es un JSON libre: puede contener lo que la
 * extracción haya sido capaz de deducir y nada más. Esta clase es el único
 * sitio donde se interpreta, para que la pantalla de revisión y la revisión
 * por lotes no puedan discrepar sobre qué significa una propuesta.
 */
final readonly class ProposedData
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(private array $data)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function name(): ?string
    {
        return $this->text($this->data['providerName'] ?? null) ?? $this->text($this->data['serviceName'] ?? null);
    }

    public function planName(): ?string
    {
        return $this->text($this->data['plan'] ?? null);
    }

    public function currency(): Currency
    {
        $currency = $this->data['currency'] ?? null;

        return is_string($currency) ? (Currency::tryFrom($currency) ?? Currency::EUR) : Currency::EUR;
    }

    /**
     * La periodicidad deducida. Si el pipeline no la ha determinado se asume
     * mensual, que es el caso mayoritario y el único supuesto razonable para
     * proponer algo al usuario.
     */
    public function billingPeriod(): BillingPeriod
    {
        $period = $this->data['billingPeriod'] ?? null;

        if (!is_string($period)) {
            return BillingPeriod::MONTHLY;
        }

        $value = BillingPeriod::tryFrom($period);

        return null === $value || BillingPeriod::UNKNOWN === $value ? BillingPeriod::MONTHLY : $value;
    }

    public function amount(): ?Money
    {
        $amountMinor = $this->data['amountMinor'] ?? null;

        if (!is_numeric($amountMinor)) {
            return null;
        }

        return Money::of((int) $amountMinor, $this->currency());
    }

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->date($this->data['invoiceDate'] ?? null);
    }

    public function renewalAt(): ?DateTimeImmutable
    {
        return $this->date($this->data['renewalDate'] ?? null);
    }

    public function dueAt(): ?DateTimeImmutable
    {
        return $this->date($this->data['dueDate'] ?? null);
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
