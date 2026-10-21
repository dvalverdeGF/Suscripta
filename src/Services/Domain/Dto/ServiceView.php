<?php

declare(strict_types=1);

namespace App\Services\Domain\Dto;

use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Enum\ServiceSource;
use App\Services\Domain\Enum\ServiceStatus;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;

use function sprintf;

use Symfony\Component\Uid\Uuid;

/**
 * Vista de lectura de un servicio (ARCHITECTURE.md §3, §4.3).
 *
 * Es el **read model** del módulo `Services`. La entidad `Service` sigue siendo
 * la fuente de verdad y la única que se puede escribir, pero leerla desde otros
 * módulos acopla el producto entero a su forma interna: cualquier campo nuevo
 * obligaría a tocar el dashboard, las alertas y el pipeline.
 *
 * Por eso la lectura va por un DTO. Este DTO:
 *
 * - expone **propiedades públicas de solo lectura**, no getters, porque así la
 *   plantilla de Twig y el código de aplicación acceden igual y no depende de
 *   una convención de nombres que se puede romper sin que nadie se entere;
 * - no expone `Provider` ni `Category` (son de otro módulo): solo sus
 *   identificadores, y quien necesite el nombre lo resuelve en su propio
 *   módulo;
 * - mantiene la **identidad de objeto** del precio vigente: `currentPrice` es
 *   la misma instancia que aparece en `prices`, de modo que "el vigente" se
 *   puede comprobar con `===` y no comparando importes.
 */
final readonly class ServiceView
{
    /**
     * @param list<ServicePriceView> $prices
     * @param list<ServiceEventView> $events
     * @param ServicePriceView|null  $currentPrice precio vigente; `null` si el servicio no tiene ninguno
     */
    public function __construct(
        public Uuid $id,
        public string $name,
        public ?string $planName,
        public ServiceStatus $status,
        public ServiceSource $source,
        public Currency $currency,
        public BillingPeriod $billingPeriod,
        public int $billingIntervalCount,
        public ?Uuid $providerId,
        public ?Uuid $categoryId,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $nextChargeAt,
        public ?DateTimeImmutable $renewalAt,
        public ?DateTimeImmutable $commitmentEndAt,
        public ?DateTimeImmutable $cancelledAt,
        public ?int $noticePeriodDays,
        public bool $autoRenews,
        public ?string $paymentMethodLabel,
        public ?string $notes,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public array $prices = [],
        public array $events = [],
        public ?ServicePriceView $currentPrice = null,
        public ?Money $currentAmount = null,
        public ?DateTimeImmutable $noticeDeadline = null,
    ) {
    }

    /**
     * Construye la vista a partir de la entidad.
     *
     * La conversión vive en el módulo que posee la entidad: nadie más debería
     * saber cómo se llama un campo interno.
     */
    public static function fromService(Service $service): self
    {
        $prices = [];
        foreach ($service->getPrices() as $price) {
            $prices[] = ServicePriceView::fromPrice($price);
        }

        $events = [];
        foreach ($service->getEvents() as $event) {
            $events[] = ServiceEventView::fromEvent($event);
        }

        $currentPrice = null;
        $entityCurrentPrice = $service->getCurrentPrice();

        if (null !== $entityCurrentPrice) {
            foreach ($prices as $price) {
                if ($price->id->equals($entityCurrentPrice->getId())) {
                    $currentPrice = $price;
                    break;
                }
            }
        }

        return new self(
            id: $service->getId(),
            name: $service->getName(),
            planName: $service->getPlanName(),
            status: $service->getStatus(),
            source: $service->getSource(),
            currency: $service->getCurrency(),
            billingPeriod: $service->getBillingPeriod(),
            billingIntervalCount: $service->getBillingIntervalCount(),
            providerId: $service->getProviderId(),
            categoryId: $service->getCategoryId(),
            startedAt: $service->getStartedAt(),
            nextChargeAt: $service->getNextChargeAt(),
            renewalAt: $service->getRenewalAt(),
            commitmentEndAt: $service->getCommitmentEndAt(),
            cancelledAt: $service->getCancelledAt(),
            noticePeriodDays: $service->getNoticePeriodDays(),
            autoRenews: $service->isAutoRenews(),
            paymentMethodLabel: $service->getPaymentMethodLabel(),
            notes: $service->getNotes(),
            createdAt: $service->getCreatedAt(),
            updatedAt: $service->getUpdatedAt(),
            prices: $prices,
            events: $events,
            currentPrice: $currentPrice,
            currentAmount: $currentPrice?->amount,
            noticeDeadline: self::computeNoticeDeadline($service->getRenewalAt(), $service->getNoticePeriodDays()),
        );
    }

    /**
     * Fecha límite para avisar de la renovación: la renovación menos el
     * preaviso. `null` si no hay renovación o no hay preaviso definido.
     */
    private static function computeNoticeDeadline(?DateTimeImmutable $renewalAt, ?int $noticePeriodDays): ?DateTimeImmutable
    {
        if (null === $renewalAt || null === $noticePeriodDays) {
            return null;
        }

        return $renewalAt->modify(sprintf('-%d days', $noticePeriodDays));
    }

    /**
     * Precio que estaba vigente en la fecha indicada.
     *
     * Es lo que permite reconstruir cuánto costaba el servicio en el pasado sin
     * proyectar hacia atrás el precio de hoy.
     */
    public function priceAt(DateTimeImmutable $date): ?ServicePriceView
    {
        $match = null;

        foreach ($this->prices as $price) {
            if (!$price->covers($date)) {
                continue;
            }

            if (null === $match || $price->validFrom > $match->validFrom) {
                $match = $price;
            }
        }

        return $match;
    }

    /**
     * Eventos de un tipo concreto, del más reciente al más antiguo.
     *
     * @return list<ServiceEventView>
     */
    public function eventsOfType(ServiceEventType $type): array
    {
        $matching = [];

        foreach ($this->events as $event) {
            if ($event->type === $type) {
                $matching[] = $event;
            }
        }

        return $matching;
    }
}
