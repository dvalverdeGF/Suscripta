<?php

declare(strict_types=1);

namespace App\Discovery\UI\Form;

use App\Discovery\Application\Dto\DiscoveryInput;
use App\Discovery\Domain\Entity\Discovery;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;

use function is_numeric;
use function is_string;

use Symfony\Component\Uid\Uuid;
use Throwable;

use function trim;

/**
 * Objeto mutable que rellena el formulario de revisión de una propuesta.
 *
 * Se precarga con lo que el pipeline ha deducido, para que el caso normal sea
 * *leer y confirmar* en lugar de *teclear*. Todo lo que el usuario cambie se
 * guarda tal cual: la propuesta original se conserva aparte para poder medir la
 * precisión real del sistema.
 *
 * Las propiedades escalares son anulables por la misma razón que en
 * `ServiceFormData`: Symfony 8 traduce la cadena vacía a `null`.
 */
final class DiscoveryFormData
{
    public ?string $name = null;
    public ?Uuid $providerId = null;
    public ?Uuid $categoryId = null;
    public ?string $planName = null;
    public ?Money $amount = null;
    public ?Currency $currency = Currency::EUR;
    public ?BillingPeriod $billingPeriod = BillingPeriod::MONTHLY;
    public ?int $billingIntervalCount = 1;
    public ?DateTimeImmutable $startedAt = null;
    public ?DateTimeImmutable $nextChargeAt = null;
    public ?DateTimeImmutable $renewalAt = null;
    public ?int $noticePeriodDays = null;
    public bool $autoRenews = true;
    public ?string $notes = null;

    public static function fromDiscovery(Discovery $discovery): self
    {
        $data = new self();
        $proposed = $discovery->getProposedData();

        $data->name = self::string($proposed['providerName'] ?? null)
            ?? self::string($proposed['serviceName'] ?? null);
        $data->planName = self::string($proposed['plan'] ?? null);
        $data->currency = self::currency($proposed['currency'] ?? null);
        $data->billingPeriod = self::period($proposed['billingPeriod'] ?? null);
        $data->amount = self::money($proposed['amountMinor'] ?? null, $data->currency);
        $data->startedAt = self::date($proposed['invoiceDate'] ?? null);
        $data->renewalAt = self::date($proposed['renewalDate'] ?? null);
        $data->nextChargeAt = $data->renewalAt ?? self::date($proposed['dueDate'] ?? null);

        return $data;
    }

    public function toInput(): DiscoveryInput
    {
        return new DiscoveryInput(
            name: $this->name ?? '',
            currency: $this->currency ?? Currency::EUR,
            billingPeriod: $this->billingPeriod ?? BillingPeriod::MONTHLY,
            billingIntervalCount: $this->billingIntervalCount ?? 1,
            amount: $this->amount,
            providerId: $this->providerId,
            categoryId: $this->categoryId,
            planName: $this->planName,
            startedAt: $this->startedAt,
            nextChargeAt: $this->nextChargeAt,
            renewalAt: $this->renewalAt,
            noticePeriodDays: $this->noticePeriodDays,
            autoRenews: $this->autoRenews,
            notes: $this->notes,
        );
    }

    private static function string(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private static function currency(mixed $value): Currency
    {
        return is_string($value) ? (Currency::tryFrom($value) ?? Currency::EUR) : Currency::EUR;
    }

    private static function period(mixed $value): BillingPeriod
    {
        if (!is_string($value)) {
            return BillingPeriod::MONTHLY;
        }

        $period = BillingPeriod::tryFrom($value);

        return null === $period || BillingPeriod::UNKNOWN === $period ? BillingPeriod::MONTHLY : $period;
    }

    private static function money(mixed $amountMinor, Currency $currency): ?Money
    {
        if (!is_numeric($amountMinor)) {
            return null;
        }

        return Money::of((int) $amountMinor, $currency);
    }

    private static function date(mixed $value): ?DateTimeImmutable
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
