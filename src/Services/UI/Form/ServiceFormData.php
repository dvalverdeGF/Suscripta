<?php

declare(strict_types=1);

namespace App\Services\UI\Form;

use App\Services\Application\Dto\ServiceInput;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceSource;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Objeto mutable que rellena el formulario de servicio.
 *
 * `ServiceInput` es `readonly` y no puede ser el `data_class` de un formulario
 * de Symfony, que necesita escribir en él. Este DTO hace de puente y se
 * convierte a `ServiceInput` en el controlador.
 *
 * Todas las propiedades escalares son anulables a propósito. Desde Symfony 8,
 * `Form::viewToNorm()` traduce la cadena vacía a `null` cuando el campo no
 * declara un `empty_data` explícito, así que un envío vacío escribe `null` en el
 * DTO. Con propiedades no anulables eso revienta con un `TypeError` (error 500)
 * en lugar de producir un error de validación. Los valores por defecto siguen
 * ahí para que el formulario se pinte con una selección razonable; las
 * restricciones `NotBlank`/`NotNull` de `ServiceFormType` son las que impiden
 * que un `null` llegue al dominio.
 */
final class ServiceFormData
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
    public ?DateTimeImmutable $commitmentEndAt = null;
    public ?string $paymentMethodLabel = null;
    public ?string $notes = null;

    public static function fromService(Service $service): self
    {
        $data = new self();
        $data->name = $service->getName();
        $data->providerId = $service->getProviderId();
        $data->categoryId = $service->getCategoryId();
        $data->planName = $service->getPlanName();
        $data->amount = $service->getCurrentAmount();
        $data->currency = $service->getCurrency();
        $data->billingPeriod = $service->getBillingPeriod();
        $data->billingIntervalCount = $service->getBillingIntervalCount();
        $data->startedAt = $service->getStartedAt();
        $data->nextChargeAt = $service->getNextChargeAt();
        $data->renewalAt = $service->getRenewalAt();
        $data->noticePeriodDays = $service->getNoticePeriodDays();
        $data->autoRenews = $service->isAutoRenews();
        $data->commitmentEndAt = $service->getCommitmentEndAt();
        $data->paymentMethodLabel = $service->getPaymentMethodLabel();
        $data->notes = $service->getNotes();

        return $data;
    }

    public function toInput(ServiceSource $source = ServiceSource::MANUAL): ServiceInput
    {
        return new ServiceInput(
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
            commitmentEndAt: $this->commitmentEndAt,
            paymentMethodLabel: $this->paymentMethodLabel,
            notes: $this->notes,
            source: $source,
        );
    }
}
