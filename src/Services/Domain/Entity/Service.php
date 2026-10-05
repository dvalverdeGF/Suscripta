<?php

declare(strict_types=1);

namespace App\Services\Domain\Entity;

use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Enum\ServiceSource;
use App\Services\Domain\Enum\ServiceStatus;
use App\Shared\Domain\Contract\TenantAwareInterface;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function sprintf;

use Symfony\Component\Uid\Uuid;

/**
 * Servicio recurrente que la organización paga (ARCHITECTURE.md §4.3).
 *
 * Es el núcleo del producto: responde a "¿qué estoy pagando, cuánto y cuándo
 * me lo vuelven a cobrar?".
 *
 * `providerId` y `categoryId` se guardan como UUID, no como relación ORM: el
 * catálogo vive en otro módulo y un módulo nunca accede a las entidades de
 * otro (ARCHITECTURE.md §3). Los nombres se resuelven por la capa Application
 * de `Catalog`.
 *
 * El precio **no** es un campo de esta entidad: vive en `ServicePrice` como
 * historial (D-13). Aquí solo se guarda la periodicidad vigente, que es
 * información de negocio del servicio y no del importe.
 */
#[ORM\Entity]
#[ORM\Table(name: 'service')]
#[ORM\Index(name: 'idx_service_organization_status', columns: ['organization_id', 'status'])]
#[ORM\Index(name: 'idx_service_next_charge', columns: ['organization_id', 'next_charge_at'])]
class Service implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    #[ORM\Column(name: 'provider_id', type: 'uuid', nullable: true)]
    private ?Uuid $providerId = null;

    #[ORM\Column(name: 'category_id', type: 'uuid', nullable: true)]
    private ?Uuid $categoryId = null;

    #[ORM\Column(type: Types::STRING, length: 160)]
    private string $name;

    #[ORM\Column(name: 'plan_name', type: Types::STRING, length: 160, nullable: true)]
    private ?string $planName = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ServiceStatus::class)]
    private ServiceStatus $status;

    #[ORM\Column(type: Types::STRING, length: 3, enumType: Currency::class)]
    private Currency $currency;

    #[ORM\Column(name: 'billing_period', type: Types::STRING, length: 20, enumType: BillingPeriod::class)]
    private BillingPeriod $billingPeriod;

    #[ORM\Column(name: 'billing_interval_count', type: Types::SMALLINT)]
    private int $billingIntervalCount = 1;

    #[ORM\Column(name: 'started_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'next_charge_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $nextChargeAt = null;

    #[ORM\Column(name: 'renewal_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $renewalAt = null;

    #[ORM\Column(name: 'notice_period_days', type: Types::SMALLINT, nullable: true)]
    private ?int $noticePeriodDays = null;

    #[ORM\Column(name: 'auto_renews', type: Types::BOOLEAN)]
    private bool $autoRenews = true;

    #[ORM\Column(name: 'commitment_end_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $commitmentEndAt = null;

    #[ORM\Column(name: 'cancelled_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(name: 'payment_method_label', type: Types::STRING, length: 80, nullable: true)]
    private ?string $paymentMethodLabel = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ServiceSource::class)]
    private ServiceSource $source;

    #[ORM\Column(name: 'created_by_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $createdByUserId = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /** @var Collection<int, ServicePrice> */
    #[ORM\OneToMany(targetEntity: ServicePrice::class, mappedBy: 'service', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['validFrom' => 'ASC'])]
    private Collection $prices;

    /** @var Collection<int, ServiceEvent> */
    #[ORM\OneToMany(targetEntity: ServiceEvent::class, mappedBy: 'service', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['occurredAt' => 'DESC'])]
    private Collection $events;

    public function __construct(
        Uuid $organizationId,
        string $name,
        Currency $currency,
        BillingPeriod $billingPeriod,
        ServiceSource $source = ServiceSource::MANUAL,
        ServiceStatus $status = ServiceStatus::ACTIVE,
    ) {
        $name = trim($name);

        if ('' === $name) {
            throw new InvalidArgumentException('El nombre del servicio no puede estar vacío.');
        }

        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->name = $name;
        $this->currency = $currency;
        $this->billingPeriod = $billingPeriod;
        $this->source = $source;
        $this->status = $status;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->prices = new ArrayCollection();
        $this->events = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOrganizationId(): Uuid
    {
        return $this->organizationId;
    }

    public function setOrganizationId(Uuid $organizationId): void
    {
        $this->organizationId = $organizationId;
    }

    public function getProviderId(): ?Uuid
    {
        return $this->providerId;
    }

    public function setProviderId(?Uuid $providerId): void
    {
        $this->providerId = $providerId;
        $this->touch();
    }

    public function getCategoryId(): ?Uuid
    {
        return $this->categoryId;
    }

    public function setCategoryId(?Uuid $categoryId): void
    {
        $this->categoryId = $categoryId;
        $this->touch();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $name = trim($name);

        if ('' === $name) {
            throw new InvalidArgumentException('El nombre del servicio no puede estar vacío.');
        }

        $this->name = $name;
        $this->touch();
    }

    public function getPlanName(): ?string
    {
        return $this->planName;
    }

    public function setPlanName(?string $planName): void
    {
        $this->planName = null === $planName ? null : (trim($planName) ?: null);
        $this->touch();
    }

    public function getStatus(): ServiceStatus
    {
        return $this->status;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getBillingPeriod(): BillingPeriod
    {
        return $this->billingPeriod;
    }

    public function getBillingIntervalCount(): int
    {
        return $this->billingIntervalCount;
    }

    /**
     * Cambiar la periodicidad no toca el precio: el importe sigue siendo el de
     * la fila vigente de `ServicePrice`. Solo se recalcula el próximo cobro.
     */
    public function changeBillingPeriod(BillingPeriod $period, int $intervalCount = 1): void
    {
        if ($intervalCount < 1) {
            throw new InvalidArgumentException('El intervalo de facturación debe ser al menos 1.');
        }

        $this->billingPeriod = $period;
        $this->billingIntervalCount = $intervalCount;
        $this->recalculateNextCharge();
        $this->touch();
    }

    public function getStartedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?DateTimeImmutable $startedAt): void
    {
        $this->startedAt = $startedAt;
        $this->recalculateNextCharge();
        $this->touch();
    }

    public function getNextChargeAt(): ?DateTimeImmutable
    {
        return $this->nextChargeAt;
    }

    public function setNextChargeAt(?DateTimeImmutable $nextChargeAt): void
    {
        $this->nextChargeAt = $nextChargeAt;
        $this->touch();
    }

    public function getRenewalAt(): ?DateTimeImmutable
    {
        return $this->renewalAt;
    }

    public function setRenewalAt(?DateTimeImmutable $renewalAt): void
    {
        $this->renewalAt = $renewalAt;
        $this->touch();
    }

    public function getNoticePeriodDays(): ?int
    {
        return $this->noticePeriodDays;
    }

    public function setNoticePeriodDays(?int $noticePeriodDays): void
    {
        if (null !== $noticePeriodDays && $noticePeriodDays < 0) {
            throw new InvalidArgumentException('El preaviso no puede ser negativo.');
        }

        $this->noticePeriodDays = $noticePeriodDays;
        $this->touch();
    }

    public function isAutoRenews(): bool
    {
        return $this->autoRenews;
    }

    public function setAutoRenews(bool $autoRenews): void
    {
        $this->autoRenews = $autoRenews;
        $this->touch();
    }

    public function getCommitmentEndAt(): ?DateTimeImmutable
    {
        return $this->commitmentEndAt;
    }

    public function setCommitmentEndAt(?DateTimeImmutable $commitmentEndAt): void
    {
        $this->commitmentEndAt = $commitmentEndAt;
        $this->touch();
    }

    public function getCancelledAt(): ?DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getPaymentMethodLabel(): ?string
    {
        return $this->paymentMethodLabel;
    }

    public function setPaymentMethodLabel(?string $paymentMethodLabel): void
    {
        $this->paymentMethodLabel = null === $paymentMethodLabel ? null : (trim($paymentMethodLabel) ?: null);
        $this->touch();
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): void
    {
        $this->notes = null === $notes ? null : (trim($notes) ?: null);
        $this->touch();
    }

    public function getSource(): ServiceSource
    {
        return $this->source;
    }

    public function getCreatedByUserId(): ?Uuid
    {
        return $this->createdByUserId;
    }

    public function setCreatedByUserId(?Uuid $createdByUserId): void
    {
        $this->createdByUserId = $createdByUserId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, ServicePrice> */
    /** @return Collection<int, ServicePrice> */
    public function getPrices(): Collection
    {
        return $this->prices;
    }

    /** @return Collection<int, ServiceEvent> */
    public function getEvents(): Collection
    {
        return $this->events;
    }

    /**
     * Precio vigente: la fila con `validTo IS NULL` (D-13).
     *
     * Si no hay ninguna fila abierta (datos importados a medias), se devuelve la
     * más reciente cerrada: es mejor mostrar el último precio conocido que
     * mostrar cero.
     */
    public function getCurrentPrice(): ?ServicePrice
    {
        $latest = null;

        foreach ($this->prices as $price) {
            if (null === $price->getValidTo()) {
                return $price;
            }

            if (null === $latest || $price->getValidFrom() > $latest->getValidFrom()) {
                $latest = $price;
            }
        }

        return $latest;
    }

    public function getCurrentAmount(): ?Money
    {
        return $this->getCurrentPrice()?->getAmount();
    }

    /**
     * Precio que estaba vigente en una fecha concreta.
     *
     * Es lo que permite reconstruir cuánto costaba el servicio hace seis meses
     * sin proyectar hacia atrás el precio de hoy, que es justo el error que
     * hace que un usuario no se crea la evolución del gasto.
     */
    public function getPriceAt(DateTimeImmutable $date): ?ServicePrice
    {
        $match = null;

        foreach ($this->prices as $price) {
            if (!$price->covers($date)) {
                continue;
            }

            // Si dos filas se solapan (datos importados a mano), gana la que
            // entró en vigor más tarde: es la que el usuario esperaría ver.
            if (null === $match || $price->getValidFrom() > $match->getValidFrom()) {
                $match = $price;
            }
        }

        return $match;
    }

    /**
     * Registra un precio nuevo cerrando el anterior.
     *
     * Es la única forma de cambiar el importe: nunca se muta una fila de
     * historial. Devuelve `true` si el importe realmente cambió, para que quien
     * llama decida si genera un evento y una alerta.
     */
    public function changePrice(
        Money $amount,
        DateTimeImmutable $validFrom,
        ServiceSource $source = ServiceSource::MANUAL,
        ?string $note = null,
        ?Uuid $invoiceId = null,
    ): bool {
        if ($amount->currency !== $this->currency) {
            throw new InvalidArgumentException(sprintf('El servicio está en %s y el importe en %s.', $this->currency->value, $amount->currency->value));
        }

        $current = $this->getCurrentPrice();

        if (null !== $current && $current->getAmount()->equals($amount)) {
            return false;
        }

        if (null !== $current) {
            $current->close($validFrom);
        }

        $this->prices->add(new ServicePrice($this, $amount, $validFrom, $source, $note, $invoiceId));
        $this->touch();

        return true;
    }

    /**
     * @param array<string, scalar|null> $data
     */
    public function recordEvent(
        ServiceEventType $type,
        ?Uuid $actorUserId = null,
        array $data = [],
        ?DateTimeImmutable $occurredAt = null,
    ): ServiceEvent {
        $event = new ServiceEvent($this, $type, $occurredAt ?? new DateTimeImmutable(), $data, $actorUserId);
        $this->events->add($event);

        return $event;
    }

    public function pause(?Uuid $actorUserId = null): void
    {
        if (ServiceStatus::PAUSED === $this->status) {
            return;
        }

        $this->status = ServiceStatus::PAUSED;
        $this->recordEvent(ServiceEventType::PAUSED, $actorUserId);
        $this->touch();
    }

    public function resume(?Uuid $actorUserId = null): void
    {
        if (ServiceStatus::ACTIVE === $this->status) {
            return;
        }

        $this->status = ServiceStatus::ACTIVE;
        $this->cancelledAt = null;
        $this->recordEvent(ServiceEventType::RESUMED, $actorUserId);
        $this->touch();
    }

    public function cancel(DateTimeImmutable $at, ?Uuid $actorUserId = null): void
    {
        if (ServiceStatus::CANCELLED === $this->status) {
            return;
        }

        $this->status = ServiceStatus::CANCELLED;
        $this->cancelledAt = $at;
        $this->nextChargeAt = null;
        $this->recordEvent(ServiceEventType::CANCELLED, $actorUserId, ['cancelledAt' => $at->format('Y-m-d')], $at);
        $this->touch();
    }

    /**
     * Confirma un servicio propuesto por el pipeline: pasa de `pending_review`
     * a `active` y deja constancia de que lo descubrió el sistema.
     */
    public function confirmFromDiscovery(?Uuid $actorUserId = null): void
    {
        $this->status = ServiceStatus::ACTIVE;
        $this->recordEvent(ServiceEventType::DISCOVERED, $actorUserId);
        $this->touch();
    }

    /**
     * Recalcula el próximo cobro.
     *
     * Es idempotente: llamarlo dos veces seguidas da el mismo resultado. La
     * referencia es siempre el último cobro conocido (o, si no lo hay, la fecha
     * de alta), nunca el propio `nextChargeAt`, que es un resultado y no una
     * entrada.
     *
     * Si el resultado cae en el pasado —un servicio que lleva años activo y del
     * que solo conocemos la fecha de alta— se avanza hasta la primera fecha
     * futura. Un "próximo cobro" en el pasado no es información, es ruido.
     *
     * @param DateTimeImmutable|null $lastChargeAt último cobro conocido
     * @param DateTimeImmutable|null $now          referencia para el cálculo
     */
    public function recalculateNextCharge(?DateTimeImmutable $lastChargeAt = null, ?DateTimeImmutable $now = null): void
    {
        if (!$this->billingPeriod->isRecurring()) {
            return;
        }

        $base = $lastChargeAt ?? $this->startedAt;

        if (null === $base) {
            return;
        }

        $now ??= new DateTimeImmutable('today');
        $next = self::advance($base, $this->billingPeriod, $this->billingIntervalCount);

        // Tope de seguridad: 200 iteraciones cubren de sobra cualquier servicio
        // real (un semanal de 4 años son 208 cobros, y para entonces la fecha de
        // alta ya no es la referencia).
        for ($i = 0; $i < 200 && $next < $now; ++$i) {
            $next = self::advance($next, $this->billingPeriod, $this->billingIntervalCount);
        }

        $this->nextChargeAt = $next;
    }

    /**
     * Avanza una fecha según la periodicidad.
     *
     * Los periodos expresables en meses se avanzan con aritmética de calendario,
     * no sumando 30 días: una suscripción mensual del 31 de enero se cobra el 28
     * de febrero, no el 2 de marzo.
     *
     * `DateTimeImmutable::modify('+1 month')` **desborda**: el 31 de enero más un
     * mes da el 3 de marzo, porque PHP normaliza el 31 de febrero. Por eso el
     * cálculo se hace a mano y se recorta al último día del mes destino.
     */
    public static function advance(DateTimeImmutable $from, BillingPeriod $period, int $intervalCount = 1): DateTimeImmutable
    {
        $months = $period->months();

        if (null !== $months) {
            return self::addMonths($from, $months * $intervalCount);
        }

        $days = $period->days();

        if (null !== $days) {
            return $from->modify(sprintf('+%d days', $days * $intervalCount));
        }

        return $from;
    }

    /**
     * Suma meses conservando el día del mes cuando existe en el mes destino y
     * recortando al último día cuando no (31 de enero + 1 mes = 28 de febrero).
     */
    private static function addMonths(DateTimeImmutable $from, int $months): DateTimeImmutable
    {
        $month = (int) $from->format('n') + $months;
        $year = (int) $from->format('Y') + intdiv($month - 1, 12);
        $month = (($month - 1) % 12 + 12) % 12 + 1;

        $day = (int) $from->format('j');
        $lastDayOfMonth = (int) $from->setDate($year, $month, 1)->format('t');

        return $from->setDate($year, $month, min($day, $lastDayOfMonth));
    }

    /**
     * Fecha límite para avisar de una renovación: la renovación menos el
     * preaviso. Null si no hay renovación o no hay preaviso definido.
     */
    public function getNoticeDeadline(): ?DateTimeImmutable
    {
        if (null === $this->renewalAt || null === $this->noticePeriodDays) {
            return null;
        }

        return $this->renewalAt->modify(sprintf('-%d days', $this->noticePeriodDays));
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
