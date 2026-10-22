<?php

declare(strict_types=1);

namespace App\Api\Application;

use App\Api\Application\Dto\ConfirmProposalRequest;
use App\Api\Application\ReadModel\ResourceMapper;
use App\Api\Domain\Exception\ResourceNotFoundException;
use App\Discovery\Application\ConfirmDiscovery;
use App\Discovery\Application\Dto\DiscoveryInput;
use App\Discovery\Application\Dto\ProposedData;
use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Discovery\Domain\Repository\DiscoveryViewRepositoryInterface;
use App\Shared\Domain\ValueObject\BillingPeriod;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * Confirmación de una propuesta desde la API.
 *
 * ## Qué se guarda
 *
 * Lo que el integrador ha mandado, si ha mandado algo. Si no ha mandado
 * nada, lo que el pipeline propuso. En los dos casos la propuesta original
 * queda intacta en `proposedData`, de modo que se puede seguir midiendo la
 * precisión del extractor (ARCHITECTURE.md §13.11).
 *
 * ## La propuesta puede no ser de esta organización
 *
 * La búsqueda de la propuesta pasa por el filtro de inquilino, así que una
 * propuesta de otra organización es simplemente «no existe» para la API. No
 * hay forma de confirmarla desde aquí.
 */
final readonly class ConfirmApiProposal
{
    public function __construct(
        private DiscoveryRepositoryInterface $discoveries,
        private DiscoveryViewRepositoryInterface $views,
        private ConfirmDiscovery $confirmDiscovery,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Uuid $id, ConfirmProposalRequest $request): array
    {
        $discovery = $this->discoveries->find($id);

        if (null === $discovery) {
            throw new ResourceNotFoundException('La propuesta no existe.');
        }

        $input = $this->input($discovery, $request);

        ($this->confirmDiscovery)($discovery, $input);

        $view = $this->views->findOne($id);

        if (null === $view) {
            throw new \RuntimeException('La propuesta confirmada no se puede leer.');
        }

        return ResourceMapper::proposal($view);
    }

    private function input(Discovery $discovery, ConfirmProposalRequest $request): DiscoveryInput
    {
        $proposed = DiscoveryInput::fromProposal(ProposedData::fromArray($discovery->getProposedData()));

        $currency = null !== $request->currency
            ? ApiValueParser::currency($request->currency)
            : $proposed->currency;

        $billingPeriod = null !== $request->billingPeriod
            ? ApiValueParser::billingPeriod($request->billingPeriod)
            : $proposed->billingPeriod;

        $amount = null !== $request->amount
            ? ApiValueParser::money($request->amount, $currency)
            : $proposed->amount;

        $name = $request->name ?? $proposed->name;

        return new DiscoveryInput(
            name: $name,
            currency: $currency,
            billingPeriod: $billingPeriod ?? BillingPeriod::MONTHLY,
            billingIntervalCount: $request->billingIntervalCount ?? $proposed->billingIntervalCount,
            amount: $amount,
            providerId: null !== $request->providerId
                ? ApiValueParser::uuid($request->providerId, 'provider_id')
                : $proposed->providerId,
            categoryId: null !== $request->categoryId
                ? ApiValueParser::uuid($request->categoryId, 'category_id')
                : $proposed->categoryId,
            planName: $request->planName ?? $proposed->planName,
            startedAt: $this->date($request->startedAt, 'started_at') ?? $proposed->startedAt,
            nextChargeAt: $this->date($request->nextChargeAt, 'next_charge_at') ?? $proposed->nextChargeAt,
            renewalAt: $this->date($request->renewalAt, 'renewal_at') ?? $proposed->renewalAt,
            noticePeriodDays: $request->noticePeriodDays ?? $proposed->noticePeriodDays,
            autoRenews: $request->autoRenews ?? $proposed->autoRenews,
            notes: $request->notes ?? $proposed->notes,
        );
    }

    private function date(?string $value, string $field): ?DateTimeImmutable
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        return ApiValueParser::date($value, $field);
    }
}
