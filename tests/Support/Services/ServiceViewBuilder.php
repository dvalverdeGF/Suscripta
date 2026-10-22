<?php

declare(strict_types=1);

namespace App\Tests\Support\Services;

use App\Services\Domain\Dto\ServiceEventView;
use App\Services\Domain\Dto\ServicePriceView;
use App\Services\Domain\Dto\ServiceView;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Enum\ServiceSource;
use App\Services\Domain\Enum\ServiceStatus;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Construye vistas de servicio sintéticas para los tests.
 *
 * Los detectores de inferencia (Fase 15) trabajan sobre el **read model**, no
 * sobre la entidad: por eso los tests necesitan poder montar una vista con
 * exactamente los campos que mira cada detector —proveedor, importe,
 * periodicidad, eventos— sin tener que arrastrar la entidad entera.
 */
final class ServiceViewBuilder
{
    private Uuid $id;
    private string $name;
    private ?string $planName = null;
    private ServiceStatus $status = ServiceStatus::ACTIVE;
    private Currency $currency = Currency::EUR;
    private BillingPeriod $billingPeriod = BillingPeriod::MONTHLY;
    private int $billingIntervalCount = 1;
    private ?Uuid $providerId = null;
    private ?Uuid $categoryId = null;
    private ?DateTimeImmutable $startedAt = null;
    private ?DateTimeImmutable $nextChargeAt = null;
    private ?DateTimeImmutable $createdAt = null;
    private ?Money $amount = null;
    private bool $hasPrice = true;

    /** @var list<ServiceEventView> */
    private array $events = [];

    public static function create(string $name = 'Netflix'): self
    {
        $builder = new self();
        $builder->name = $name;
        $builder->id = Uuid::v7();

        return $builder;
    }

    public function withId(Uuid $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function withPlan(?string $planName): self
    {
        $this->planName = $planName;

        return $this;
    }

    public function withStatus(ServiceStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function withProvider(?Uuid $providerId): self
    {
        $this->providerId = $providerId;

        return $this;
    }

    public function withCategory(?Uuid $categoryId): self
    {
        $this->categoryId = $categoryId;

        return $this;
    }

    public function withPeriod(BillingPeriod $period, int $intervalCount = 1): self
    {
        $this->billingPeriod = $period;
        $this->billingIntervalCount = $intervalCount;

        return $this;
    }

    public function withAmount(?Money $amount): self
    {
        $this->amount = $amount;
        $this->hasPrice = null !== $amount;

        return $this;
    }

    public function withNextChargeAt(?DateTimeImmutable $nextChargeAt): self
    {
        $this->nextChargeAt = $nextChargeAt;

        return $this;
    }

    public function withStartedAt(?DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function withCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function withEvent(ServiceEventType $type, DateTimeImmutable $occurredAt): self
    {
        $this->events[] = new ServiceEventView(
            id: Uuid::v7(),
            type: $type,
            occurredAt: $occurredAt,
            data: [],
        );

        return $this;
    }

    public function build(): ServiceView
    {
        $createdAt = $this->createdAt ?? new DateTimeImmutable('2026-01-01 00:00:00');
        $price = null;

        if ($this->hasPrice) {
            $price = new ServicePriceView(
                id: Uuid::v7(),
                amount: $this->amount ?? Money::of(1000, $this->currency),
                validFrom: $createdAt,
                validTo: null,
                source: ServiceSource::MANUAL,
                note: null,
                isCurrent: true,
            );
        }

        return new ServiceView(
            id: $this->id,
            name: $this->name,
            planName: $this->planName,
            status: $this->status,
            source: ServiceSource::MANUAL,
            currency: $this->currency,
            billingPeriod: $this->billingPeriod,
            billingIntervalCount: $this->billingIntervalCount,
            providerId: $this->providerId,
            categoryId: $this->categoryId,
            startedAt: $this->startedAt,
            nextChargeAt: $this->nextChargeAt,
            renewalAt: null,
            commitmentEndAt: null,
            cancelledAt: null,
            noticePeriodDays: null,
            autoRenews: true,
            paymentMethodLabel: null,
            notes: null,
            createdAt: $createdAt,
            updatedAt: $createdAt,
            prices: null === $price ? [] : [$price],
            events: $this->events,
            currentPrice: $price,
            currentAmount: $price?->amount,
            noticeDeadline: null,
        );
    }
}
