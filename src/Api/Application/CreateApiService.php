<?php

declare(strict_types=1);

namespace App\Api\Application;

use App\Api\Application\Dto\CreateServiceRequest;
use App\Api\Application\ReadModel\ResourceMapper;
use App\Services\Application\CreateService;
use App\Services\Application\Dto\ServiceInput;
use App\Services\Domain\Enum\ServiceSource;
use App\Services\Domain\Repository\ServiceViewRepositoryInterface;
use App\Shared\Domain\ValueObject\BillingPeriod;

/**
 * Alta de un servicio desde la API.
 *
 * ## No se duplica la lógica de alta
 *
 * Este caso de uso **traduce** la petición y llama a `CreateService`, que es
 * el mismo que usa el formulario del backoffice. Así, la API hereda gratis
 * todo lo que ese caso de uso sabe: la comprobación de cuotas del plan, el
 * registro de auditoría, el evento de creación y el cálculo del próximo
 * cobro.
 *
 * ## El origen es `manual`
 *
 * Un servicio creado por la API lo sabe: viene de una integración, no del
 * correo ni de un formulario. Queda registrado en el propio servicio, así que
 * el panel puede distinguirlo sin que nadie tenga que preguntar.
 */
final readonly class CreateApiService
{
    public function __construct(
        private CreateService $createService,
        private ServiceViewRepositoryInterface $services,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(CreateServiceRequest $request): array
    {
        $currency = ApiValueParser::currency($request->currency);
        $billingPeriod = ApiValueParser::billingPeriod($request->billingPeriod) ?? BillingPeriod::MONTHLY;

        $input = new ServiceInput(
            name: $request->name,
            currency: $currency,
            billingPeriod: $billingPeriod,
            billingIntervalCount: $request->billingIntervalCount ?? 1,
            amount: ApiValueParser::money($request->amount, $currency),
            providerId: ApiValueParser::uuid($request->providerId, 'provider_id'),
            categoryId: ApiValueParser::uuid($request->categoryId, 'category_id'),
            planName: $request->planName,
            startedAt: ApiValueParser::date($request->startedAt, 'started_at'),
            nextChargeAt: ApiValueParser::date($request->nextChargeAt, 'next_charge_at'),
            renewalAt: ApiValueParser::date($request->renewalAt, 'renewal_at'),
            noticePeriodDays: $request->noticePeriodDays,
            autoRenews: $request->autoRenews ?? true,
            paymentMethodLabel: $request->paymentMethodLabel,
            notes: $request->notes,
            source: ServiceSource::MANUAL,
        );

        $service = ($this->createService)($input);

        $view = $this->services->findOne($service->getId());

        if (null === $view) {
            // No debería pasar: el servicio se acaba de guardar. Si pasa, es
            // que el filtro de inquilino no coincide con la organización
            // activa, y es mejor fallar en voz alta que devolver un `null`
            // silencioso.
            throw new \RuntimeException('El servicio recién creado no se puede leer.');
        }

        return ResourceMapper::service($view);
    }
}
