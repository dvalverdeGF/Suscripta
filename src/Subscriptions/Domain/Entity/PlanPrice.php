<?php

declare(strict_types=1);

namespace App\Subscriptions\Domain\Entity;

use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function in_array;
use function sprintf;

use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * Precio de un plan del catálogo (Fase 22).
 *
 * ## Por qué hay historial y no un campo `amount`
 *
 * Un precio es un dato con fecha. Si se sobrescribe, la pregunta «¿cuánto
 * costaba el plan en marzo?» deja de tener respuesta, y con ella se pierde la
 * única forma de comprobar que a un cliente se le cobró lo que se le ofreció.
 * Aquí cada cambio **cierra** la fila vigente (`validTo`) y **abre** una nueva;
 * el precio actual es la fila con `validTo IS NULL`. Es el mismo criterio que
 * el historial de precios de un servicio (D-13).
 *
 * ## Qué protege a las suscripciones vivas
 *
 * Nada de aquí toca a una suscripción: `Subscription` guarda su propio nombre
 * congelado y no lee el importe del catálogo (D-79). Cambiar el precio del plan
 * cambia lo que se ofrece a partir de ahora, no lo que ya se contrató.
 *
 * ## Periodicidades que se venden
 *
 * `BillingPeriod` tiene once casos porque describe lo que aparece en una
 * factura ajena. Aquí solo se ofrecen las que un cliente puede contratar hoy:
 * mensual, trimestral y anual. Las demás existen en el enum pero no son una
 * opción comercial.
 */
#[ORM\Entity]
#[ORM\Table(name: 'plan_price')]
#[ORM\Index(name: 'idx_plan_price_plan_valid', columns: ['plan_id', 'valid_from', 'valid_to'])]
class PlanPrice
{
    /**
     * Periodicidades que se pueden poner a la venta.
     *
     * @var list<BillingPeriod>
     */
    public const SELLABLE_PERIODS = [
        BillingPeriod::MONTHLY,
        BillingPeriod::QUARTERLY,
        BillingPeriod::ANNUAL,
    ];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * Relación unidireccional: el precio conoce su plan y el plan no carga una
     * colección de precios que casi nunca necesita. Los precios se consultan
     * por repositorio y ordenados por fecha, que es como se pintan.
     */
    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(name: 'plan_id', nullable: false, onDelete: 'CASCADE')]
    private Plan $plan;

    #[ORM\Column(name: 'amount_minor', type: Types::INTEGER)]
    private int $amountMinor;

    #[ORM\Column(type: Types::STRING, length: 3, enumType: Currency::class)]
    private Currency $currency;

    #[ORM\Column(name: 'billing_period', type: Types::STRING, length: 20, enumType: BillingPeriod::class)]
    private BillingPeriod $billingPeriod;

    #[ORM\Column(name: 'valid_from', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $validFrom;

    /**
     * `null` = precio vigente. Al cerrar una fila se fija la fecha, y a partir
     * de ahí deja de ofrecerse pero sigue explicando el pasado.
     */
    #[ORM\Column(name: 'valid_to', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $validTo = null;

    /**
     * Identificador del precio en el cobrador (Stripe, fase 24). Se guarda
     * aquí porque un `price_…` de Stripe **es** este precio, no otra cosa que
     * haya que volver a emparejar por importe y periodo.
     */
    #[ORM\Column(name: 'external_price_id', type: Types::STRING, length: 120, nullable: true)]
    private ?string $externalPriceId = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Plan $plan,
        Money $amount,
        BillingPeriod $billingPeriod,
        ?DateTimeImmutable $validFrom = null,
    ) {
        if (!in_array($billingPeriod, self::SELLABLE_PERIODS, true)) {
            throw new InvalidArgumentException(sprintf('«%s» no es una periodicidad que se pueda poner a la venta.', $billingPeriod->label()));
        }

        if ($amount->amountMinor < 0) {
            throw new InvalidArgumentException('Un precio no puede ser negativo.');
        }

        $this->id = Uuid::v7();
        $this->plan = $plan;
        $this->amountMinor = $amount->amountMinor;
        $this->currency = $amount->currency;
        $this->billingPeriod = $billingPeriod;
        $this->validFrom = $validFrom ?? new DateTimeImmutable('today');
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPlan(): Plan
    {
        return $this->plan;
    }

    public function getAmount(): Money
    {
        return Money::of($this->amountMinor, $this->currency);
    }

    public function getAmountMinor(): int
    {
        return $this->amountMinor;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getBillingPeriod(): BillingPeriod
    {
        return $this->billingPeriod;
    }

    public function getValidFrom(): DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getValidTo(): ?DateTimeImmutable
    {
        return $this->validTo;
    }

    public function getExternalPriceId(): ?string
    {
        return $this->externalPriceId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * ¿Es el precio que se ofrece hoy?
     */
    public function isCurrent(): bool
    {
        return null === $this->validTo;
    }

    /**
     * Deja de ofrecerse a partir de `$on`.
     *
     * Cerrar dos veces no es un error: el panel puede recibir la petición dos
     * veces (un doble clic) y la segunda no debe mover la fecha ya fijada.
     */
    public function close(DateTimeImmutable $on): void
    {
        if (null !== $this->validTo) {
            return;
        }

        if ($on < $this->validFrom) {
            throw new InvalidArgumentException('Un precio no puede dejar de estar vigente antes de empezar.');
        }

        $this->validTo = $on;
    }

    public function attachExternalPriceId(?string $externalPriceId): void
    {
        $externalPriceId = null === $externalPriceId ? null : trim($externalPriceId);

        $this->externalPriceId = '' === $externalPriceId ? null : $externalPriceId;
    }

    /**
     * ¿Sirve este precio para el periodo y la moneda indicados?
     */
    public function matches(BillingPeriod $billingPeriod, Currency $currency): bool
    {
        return $this->billingPeriod === $billingPeriod && $this->currency === $currency;
    }
}
