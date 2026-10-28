<?php

declare(strict_types=1);

namespace App\Subscriptions\UI\Form;

use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Subscriptions\Application\Dto\SubscriptionInput;
use App\Subscriptions\Domain\Entity\Subscription;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Puente mutable entre el formulario y `SubscriptionInput`.
 *
 * Todas las propiedades escalares son anulables por el mismo motivo que en
 * `ServiceFormData`: desde Symfony 8 la cadena vacía de un campo no requerido se
 * normaliza a `null`, y una propiedad no anulable convertiría un envío vacío en
 * un error 500 en lugar de un error de validación.
 */
final class SubscriptionFormData
{
    public ?string $name = null;
    public ?Currency $currency = Currency::EUR;
    public ?BillingPeriod $billingPeriod = BillingPeriod::MONTHLY;
    public ?int $billingIntervalCount = 1;
    public ?DateTimeImmutable $startedAt = null;
    public ?Uuid $planId = null;
    public ?Uuid $serviceId = null;
    public ?string $externalReference = null;

    public static function fromSubscription(Subscription $subscription): self
    {
        $data = new self();
        $data->name = $subscription->getName();
        $data->currency = $subscription->getCurrency();
        $data->billingPeriod = $subscription->getBillingPeriod();
        $data->billingIntervalCount = $subscription->getBillingIntervalCount();
        $data->startedAt = $subscription->getStartedAt();
        $data->planId = $subscription->getPlanId();
        $data->serviceId = $subscription->getServiceId();
        $data->externalReference = $subscription->getExternalReference();

        return $data;
    }

    public function toInput(): SubscriptionInput
    {
        return new SubscriptionInput(
            name: (string) $this->name,
            currency: $this->currency ?? Currency::EUR,
            billingPeriod: $this->billingPeriod ?? BillingPeriod::MONTHLY,
            billingIntervalCount: $this->billingIntervalCount ?? 1,
            startedAt: $this->startedAt,
            planId: $this->planId,
            serviceId: $this->serviceId,
            externalReference: $this->externalReference,
        );
    }
}
