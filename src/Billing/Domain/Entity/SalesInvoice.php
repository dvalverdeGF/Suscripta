<?php

declare(strict_types=1);

namespace App\Billing\Domain\Entity;

use App\Billing\Domain\Enum\InvoiceStatus;
use App\Shared\Domain\Contract\TenantAwareInterface;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function sprintf;

use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * Factura de venta: lo que Suscripta cobra a una organización (Fase 24).
 *
 * ## No es la factura que se descubre en el correo
 *
 * En `Services` hay facturas que el cliente **recibe** de sus proveedores y que
 * Suscripta lee del buzón. Esta es la contraria: la que Suscripta **emite** al
 * cliente por el plan contratado. Se llaman igual y no tienen nada que ver; por
 * eso esta vive en `Billing`, con nombre propio (`SalesInvoice`) y enlazada a la
 * organización, no a un servicio (D-86).
 *
 * ## El importe es una copia, no un cálculo
 *
 * Se guarda lo que la pasarela dice que se cobró, en la unidad mínima. No se
 * recalcula con el precio del catálogo porque con impuestos, cupones y
 * prorrateos el total no es «precio × meses», y una factura que no cuadra con
 * el extracto del cliente es una factura que hay que rehacer a mano.
 *
 * ## Es de la organización, no del usuario
 *
 * Quien contrata puede cambiar de puesto o irse; la factura sigue siendo de la
 * empresa. `organizationId` es la única referencia de propiedad y el filtro de
 * tenant la aísla como cualquier otra entidad (D-11).
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_invoice')]
#[ORM\UniqueConstraint(name: 'uniq_sales_invoice_external', columns: ['external_invoice_id'])]
#[ORM\Index(name: 'idx_sales_invoice_organization_issued', columns: ['organization_id', 'issued_at'])]
#[ORM\Index(name: 'idx_sales_invoice_subscription', columns: ['subscription_id'])]
class SalesInvoice implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    /**
     * La suscripción que la originó, si se puede saber. Se guarda como UUID
     * suelto y no como relación, por el mismo criterio que `Subscription`
     * (ningún módulo toca las entidades de otro).
     */
    #[ORM\Column(name: 'subscription_id', type: 'uuid', nullable: true)]
    private ?Uuid $subscriptionId = null;

    #[ORM\Column(name: 'external_invoice_id', type: Types::STRING, length: 120)]
    private string $externalInvoiceId;

    /**
     * El número que ve el cliente (`2026-0001`). Puede faltar mientras la
     * factura es un borrador en la pasarela.
     */
    #[ORM\Column(type: Types::STRING, length: 60, nullable: true)]
    private ?string $number = null;

    #[ORM\Column(name: 'amount_minor', type: Types::INTEGER)]
    private int $amountMinor;

    #[ORM\Column(type: Types::STRING, length: 3, enumType: Currency::class)]
    private Currency $currency;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: InvoiceStatus::class)]
    private InvoiceStatus $status;

    #[ORM\Column(name: 'issued_at', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $issuedAt;

    #[ORM\Column(name: 'paid_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $paidAt = null;

    #[ORM\Column(name: 'hosted_url', type: Types::STRING, length: 500, nullable: true)]
    private ?string $hostedUrl = null;

    #[ORM\Column(name: 'pdf_url', type: Types::STRING, length: 500, nullable: true)]
    private ?string $pdfUrl = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $organizationId,
        string $externalInvoiceId,
        Money $amount,
        InvoiceStatus $status,
        DateTimeImmutable $issuedAt,
        ?Uuid $subscriptionId = null,
        ?string $number = null,
    ) {
        $externalInvoiceId = trim($externalInvoiceId);

        if ('' === $externalInvoiceId) {
            throw new InvalidArgumentException('Una factura sin identificador de la pasarela no se puede deduplicar.');
        }

        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->externalInvoiceId = $externalInvoiceId;
        $this->amountMinor = $amount->amountMinor;
        $this->currency = $amount->currency;
        $this->status = $status;
        $this->issuedAt = $issuedAt;
        $this->subscriptionId = $subscriptionId;
        $this->number = null === $number ? null : (trim($number) ?: null);
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;

        if ($status->isPaid()) {
            $this->paidAt = $issuedAt;
        }
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

    public function getSubscriptionId(): ?Uuid
    {
        return $this->subscriptionId;
    }

    public function linkSubscription(?Uuid $subscriptionId): void
    {
        $this->subscriptionId = $subscriptionId;
        $this->touch();
    }

    public function getExternalInvoiceId(): string
    {
        return $this->externalInvoiceId;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function getAmount(): Money
    {
        return Money::of($this->amountMinor, $this->currency);
    }

    public function getStatus(): InvoiceStatus
    {
        return $this->status;
    }

    public function getIssuedAt(): DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getPaidAt(): ?DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getHostedUrl(): ?string
    {
        return $this->hostedUrl;
    }

    public function getPdfUrl(): ?string
    {
        return $this->pdfUrl;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Sincroniza la factura con lo que dice la pasarela.
     *
     * Es idempotente: aplicarla dos veces con los mismos datos deja la fila
     * igual. Eso importa porque el mismo evento puede llegar repetido y porque
     * la conciliación vuelve a pasar por aquí cada noche.
     */
    public function sync(
        InvoiceStatus $status,
        Money $amount,
        ?string $number = null,
        ?DateTimeImmutable $paidAt = null,
        ?string $hostedUrl = null,
        ?string $pdfUrl = null,
    ): void {
        $this->status = $status;
        $this->amountMinor = $amount->amountMinor;
        $this->currency = $amount->currency;

        if (null !== $number && '' !== trim($number)) {
            $this->number = trim($number);
        }

        if (null !== $paidAt) {
            $this->paidAt = $paidAt;
        } elseif (!$status->isPaid()) {
            // Una factura que deja de estar cobrada (anulada, incobrable) no
            // puede seguir diciendo que se pagó.
            $this->paidAt = null;
        }

        if (null !== $hostedUrl && '' !== trim($hostedUrl)) {
            $this->hostedUrl = trim($hostedUrl);
        }

        if (null !== $pdfUrl && '' !== trim($pdfUrl)) {
            $this->pdfUrl = trim($pdfUrl);
        }

        $this->touch();
    }

    /**
     * Importe con signo para los totales del panel: una anulada resta.
     */
    public function signedAmountMinor(): int
    {
        return InvoiceStatus::VOID === $this->status ? 0 : $this->amountMinor;
    }

    public function describe(): string
    {
        return sprintf(
            '%s · %s',
            $this->number ?? $this->externalInvoiceId,
            $this->getAmount()->format(),
        );
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
