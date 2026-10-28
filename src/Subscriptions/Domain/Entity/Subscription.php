<?php

declare(strict_types=1);

namespace App\Subscriptions\Domain\Entity;

use App\Shared\Domain\Contract\TenantAwareInterface;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Subscriptions\Domain\Enum\SubscriptionStatus;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function max;
use function sprintf;

use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * Suscripción de la organización a un plan del catálogo (Fase 21).
 *
 * ## Por qué no es un `Service`
 *
 * `Service` responde a «¿qué estoy pagando y cuándo me lo vuelven a cobrar?» y
 * se llena sobre todo leyendo el correo. Una `Subscription` responde a «¿qué
 * he contratado con Suscripta y en qué estado está el cobro?», y su origen es
 * un checkout. Mezclarlas obligaría a que cada factura descubierta en el correo
 * fuera una suscripción del catálogo, que es justo lo contrario de lo que son
 * (D-78).
 *
 * ## Referencias que no son relaciones
 *
 * `planId` y `serviceId` se guardan como UUID sueltos, no como relaciones ORM:
 * `Plan` vivirá en el módulo `Catalog` y `Service` en `Services`, y ningún
 * módulo toca las entidades de otro (ARCHITECTURE.md §3). Es el mismo criterio
 * que ya usan `Service::$providerId` y `Service::$categoryId`.
 *
 * `externalReference` es el identificador del cobrador (Stripe, fase 24). Se
 * guarda aquí y no en una tabla aparte porque es único por suscripción y no
 * tiene vida propia.
 */
#[ORM\Entity]
#[ORM\Table(name: 'subscription')]
#[ORM\Index(name: 'idx_subscription_organization_status', columns: ['organization_id', 'status'])]
#[ORM\Index(name: 'idx_subscription_service', columns: ['service_id'])]
#[ORM\Index(name: 'idx_subscription_plan', columns: ['plan_id'])]
class Subscription implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    /**
     * El plan del catálogo, si ya existe (fase 22). Nulo mientras la
     * suscripción se creó a mano con un nombre libre.
     */
    #[ORM\Column(name: 'plan_id', type: 'uuid', nullable: true)]
    private ?Uuid $planId = null;

    /**
     * El servicio del inventario al que corresponde, si se ha enlazado. La
     * relación es **opcional y explícita**: un servicio descubierto en una
     * factura no es una suscripción del catálogo.
     */
    #[ORM\Column(name: 'service_id', type: 'uuid', nullable: true)]
    private ?Uuid $serviceId = null;

    /**
     * Cómo se llama lo contratado. Se guarda copiado y no se lee del plan: si
     * el catálogo renombra un plan, la suscripción de un cliente no cambia de
     * nombre por detrás (mismo criterio que el precio congelado, D-79).
     */
    #[ORM\Column(type: Types::STRING, length: 160)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: SubscriptionStatus::class)]
    private SubscriptionStatus $status;

    #[ORM\Column(type: Types::STRING, length: 3, enumType: Currency::class)]
    private Currency $currency;

    #[ORM\Column(name: 'billing_period', type: Types::STRING, length: 20, enumType: BillingPeriod::class)]
    private BillingPeriod $billingPeriod;

    #[ORM\Column(name: 'billing_interval_count', type: Types::SMALLINT)]
    private int $billingIntervalCount = 1;

    #[ORM\Column(name: 'started_at', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $startedAt;

    #[ORM\Column(name: 'current_period_start', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $currentPeriodStart;

    #[ORM\Column(name: 'current_period_end', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $currentPeriodEnd;

    #[ORM\Column(name: 'trial_ends_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $trialEndsAt = null;

    #[ORM\Column(name: 'canceled_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $canceledAt = null;

    /**
     * Cuándo deja de dar servicio. Al cancelar se fija al final del periodo
     * pagado: el cliente ha pagado hasta ahí y cortarle el acceso antes sería
     * cobrarle por algo que no tiene.
     */
    #[ORM\Column(name: 'ends_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $endsAt = null;

    #[ORM\Column(name: 'external_reference', type: Types::STRING, length: 120, nullable: true)]
    private ?string $externalReference = null;

    #[ORM\Column(name: 'created_by_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $createdByUserId = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $organizationId,
        string $name,
        Currency $currency,
        BillingPeriod $billingPeriod,
        int $billingIntervalCount = 1,
        ?DateTimeImmutable $startedAt = null,
        SubscriptionStatus $status = SubscriptionStatus::ACTIVE,
        ?Uuid $createdByUserId = null,
    ) {
        $name = trim($name);

        if ('' === $name) {
            throw new InvalidArgumentException('El nombre de la suscripción no puede estar vacío.');
        }

        if (!$billingPeriod->isRecurring()) {
            throw new InvalidArgumentException(sprintf('Una suscripción no puede ser «%s»: no hay nada que renovar.', $billingPeriod->label()));
        }

        $start = $startedAt ?? new DateTimeImmutable('today');

        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->name = $name;
        $this->currency = $currency;
        $this->billingPeriod = $billingPeriod;
        $this->status = $status;
        $this->startedAt = $start;
        $this->billingIntervalCount = max(1, $billingIntervalCount);
        $this->currentPeriodStart = $start;
        $this->currentPeriodEnd = $billingPeriod->advance($start, $this->billingIntervalCount);
        $this->createdByUserId = $createdByUserId;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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

    public function getPlanId(): ?Uuid
    {
        return $this->planId;
    }

    /**
     * Cambia el plan contratado **sin tocar el periodo ni el estado**: un
     * cambio de plan no es un cobro nuevo, es el mismo contrato con otro
     * producto. El cobro lo decide el cobrador (fase 24).
     */
    public function changePlan(?Uuid $planId, ?string $name = null): void
    {
        $this->planId = $planId;

        if (null !== $name && '' !== trim($name)) {
            $this->name = trim($name);
        }

        $this->touch();
    }

    public function getServiceId(): ?Uuid
    {
        return $this->serviceId;
    }

    public function linkService(?Uuid $serviceId): void
    {
        $this->serviceId = $serviceId;
        $this->touch();
    }

    public function hasService(): bool
    {
        return null !== $this->serviceId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $name = trim($name);

        if ('' === $name) {
            throw new InvalidArgumentException('El nombre de la suscripción no puede estar vacío.');
        }

        $this->name = $name;
        $this->touch();
    }

    public function getStatus(): SubscriptionStatus
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
     * Cambia la periodicidad y **recalcula el final del periodo actual** desde
     * su inicio: si pasa de mensual a anual, el periodo en curso termina dentro
     * de un año, no dentro de un mes.
     */
    public function changeBillingPeriod(BillingPeriod $billingPeriod, int $intervalCount = 1): void
    {
        if (!$billingPeriod->isRecurring()) {
            throw new InvalidArgumentException(sprintf('Una suscripción no puede ser «%s»: no hay nada que renovar.', $billingPeriod->label()));
        }

        $this->billingPeriod = $billingPeriod;
        $this->billingIntervalCount = max(1, $intervalCount);
        $this->currentPeriodEnd = $billingPeriod->advance($this->currentPeriodStart, $this->billingIntervalCount);
        $this->touch();
    }

    public function getStartedAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCurrentPeriodStart(): DateTimeImmutable
    {
        return $this->currentPeriodStart;
    }

    public function getCurrentPeriodEnd(): DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function getTrialEndsAt(): ?DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function getCanceledAt(): ?DateTimeImmutable
    {
        return $this->canceledAt;
    }

    public function getEndsAt(): ?DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getExternalReference(): ?string
    {
        return $this->externalReference;
    }

    public function setExternalReference(?string $externalReference): void
    {
        $externalReference = null === $externalReference ? null : (trim($externalReference) ?: null);
        $this->externalReference = $externalReference;
        $this->touch();
    }

    public function getCreatedByUserId(): ?Uuid
    {
        return $this->createdByUserId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Si la suscripción está viva y, por tanto, ocupa plaza del plan. */
    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    /**
     * Arranca el periodo de prueba. Solo desde `TRIALING`, y con fecha
     * posterior a hoy: una prueba que ya venció no es una prueba.
     */
    public function startTrial(DateTimeImmutable $endsAt, ?DateTimeImmutable $today = null): void
    {
        if ($this->status->isFinal()) {
            throw new InvalidArgumentException('Una suscripción cancelada no puede volver a estar en prueba.');
        }

        if ($endsAt <= ($today ?? new DateTimeImmutable('today'))) {
            throw new InvalidArgumentException('El fin de la prueba tiene que ser posterior a hoy.');
        }

        $this->status = SubscriptionStatus::TRIALING;
        $this->trialEndsAt = $endsAt;
        $this->touch();
    }

    /**
     * Marca la suscripción como cobrada y al día.
     *
     * Si venía de `TRIALING`, el periodo empieza hoy: la prueba no se cobra y
     * arrastrarla dejaría un periodo medio consumido.
     */
    public function activate(?DateTimeImmutable $today = null): void
    {
        if ($this->status->isFinal()) {
            throw new InvalidArgumentException('Una suscripción cancelada no se puede reactivar: hay que crear otra.');
        }

        $today ??= new DateTimeImmutable('today');

        if (SubscriptionStatus::TRIALING === $this->status) {
            $this->currentPeriodStart = $today;
            $this->currentPeriodEnd = $this->billingPeriod->advance($today, $this->billingIntervalCount);
        }

        $this->status = SubscriptionStatus::ACTIVE;
        $this->trialEndsAt = null;
        $this->endsAt = null;
        $this->touch();
    }

    /** El cobro ha fallado. Se sigue dando servicio mientras se reintenta. */
    public function markPastDue(): void
    {
        if ($this->status->isFinal()) {
            throw new InvalidArgumentException('Una suscripción cancelada no puede quedar impagada.');
        }

        $this->status = SubscriptionStatus::PAST_DUE;
        $this->touch();
    }

    /**
     * Pausa voluntaria: no se cobra, pero la suscripción no se cancela y el
     * periodo no avanza.
     */
    public function pause(): void
    {
        if (SubscriptionStatus::PAUSED === $this->status) {
            return;
        }

        if ($this->status->isFinal()) {
            throw new InvalidArgumentException('Una suscripción cancelada no se puede pausar.');
        }

        $this->status = SubscriptionStatus::PAUSED;
        $this->touch();
    }

    /**
     * Reanuda una pausa.
     *
     * El periodo **se reinicia hoy** y no se reanuda donde se quedó: los días
     * de la pausa no se han cobrado, así que tampoco se deben.
     */
    public function resume(?DateTimeImmutable $today = null): void
    {
        if (SubscriptionStatus::PAUSED !== $this->status) {
            return;
        }

        $today ??= new DateTimeImmutable('today');

        $this->status = SubscriptionStatus::ACTIVE;
        $this->currentPeriodStart = $today;
        $this->currentPeriodEnd = $this->billingPeriod->advance($today, $this->billingIntervalCount);
        $this->touch();
    }

    /**
     * Cancela al final del periodo pagado.
     *
     * No se borra nada y no se corta el servicio hoy: el cliente ha pagado
     * hasta `currentPeriodEnd`, así que `endsAt` es esa fecha y el estado pasa a
     * `CANCELED` para que no se renueve.
     */
    public function cancel(?DateTimeImmutable $today = null): void
    {
        if ($this->status->isFinal()) {
            return;
        }

        $today ??= new DateTimeImmutable('today');

        $this->status = SubscriptionStatus::CANCELED;
        $this->canceledAt = $today;
        $this->endsAt = $this->currentPeriodEnd;
        $this->touch();
    }

    /**
     * Renueva el periodo: avanza el inicio al final del anterior y calcula el
     * nuevo final con la periodicidad vigente.
     *
     * Solo se renueva lo que se cobra. Una suscripción en pausa o cancelada no
     * se renueva: eso sería cobrar sin contrato.
     */
    public function renew(?DateTimeImmutable $today = null): void
    {
        if (!$this->status->isPaid()) {
            throw new InvalidArgumentException(sprintf('Solo se renueva una suscripción activa; esta está «%s».', $this->status->label()));
        }

        $today ??= new DateTimeImmutable('today');

        // Si el periodo ya venció, el nuevo empieza hoy y no en la fecha teórica:
        // si no, un worker parado dos meses generaría dos periodos pasados.
        $start = $this->currentPeriodEnd > $today ? $this->currentPeriodEnd : $today;

        $this->currentPeriodStart = $start;
        $this->currentPeriodEnd = $this->billingPeriod->advance($start, $this->billingIntervalCount);
        $this->touch();
    }

    /**
     * Copia el estado y el periodo que dicta la pasarela (fase 24).
     *
     * No se reutilizan `activate()`, `cancel()` ni `renew()` porque todos ellos
     * **calculan** el periodo a partir del estado local, y aquí el periodo no se
     * calcula: se copia. Quien cobra sabe cuándo empieza y cuándo acaba el
     * periodo pagado —un prorrateo, un aplazamiento o un cambio de plan en el
     * panel de Stripe lo cambian—, y una suscripción que dijera una fecha
     * distinta de la del cobro dejaría de servir para reclamar nada.
     *
     * Los estados finales sí fijan `canceledAt`/`endsAt` si no vienen dados: una
     * suscripción cancelada sin fecha de cancelación no se puede auditar.
     */
    public function syncWithGateway(
        SubscriptionStatus $status,
        DateTimeImmutable $currentPeriodStart,
        DateTimeImmutable $currentPeriodEnd,
        ?DateTimeImmutable $canceledAt = null,
        ?DateTimeImmutable $endsAt = null,
        ?string $externalReference = null,
    ): void {
        if ($currentPeriodEnd <= $currentPeriodStart) {
            throw new InvalidArgumentException('El periodo que dicta la pasarela termina antes de empezar.');
        }

        $this->status = $status;
        $this->currentPeriodStart = $currentPeriodStart;
        $this->currentPeriodEnd = $currentPeriodEnd;

        if (null !== $externalReference && '' !== trim($externalReference)) {
            $this->externalReference = trim($externalReference);
        }

        if ($status->isFinal()) {
            $this->canceledAt = $canceledAt ?? $this->canceledAt ?? new DateTimeImmutable('today');
            // El servicio se mantiene hasta el final del periodo pagado, que es
            // lo que la pasarela acaba de decir.
            $this->endsAt = $endsAt ?? $currentPeriodEnd;
        } else {
            $this->canceledAt = null;
            $this->endsAt = $endsAt;
        }

        if (SubscriptionStatus::TRIALING !== $status) {
            $this->trialEndsAt = null;
        }

        $this->touch();
    }

    /**
     * Días que quedan de periodo. Negativo si ya venció.
     */
    public function daysLeft(?DateTimeImmutable $today = null): int
    {
        $today ??= new DateTimeImmutable('today');

        return (int) $today->diff($this->currentPeriodEnd)->format('%r%a');
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
