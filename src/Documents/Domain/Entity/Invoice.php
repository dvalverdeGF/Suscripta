<?php

declare(strict_types=1);

namespace App\Documents\Domain\Entity;

use App\Documents\Domain\Enum\InvoiceSource;
use App\Documents\Domain\Enum\InvoiceStatus;
use App\Shared\Domain\Contract\TenantAwareInterface;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function mb_substr;
use function sprintf;

use Symfony\Component\Uid\Uuid;

/**
 * Un cobro documentado (ARCHITECTURE.md §4.4).
 *
 * `Invoice` no es lo mismo que `Document`: el documento es el fichero, la
 * factura es el hecho económico. Una factura puede existir sin documento (la
 * vimos en el cuerpo del correo) y un documento puede no ser una factura (un
 * contrato). Ver D-19.
 *
 * `Payment` no es una entidad en v1 (D-04): un cobro observado es una factura
 * con `paidAt` y estado `paid`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice')]
#[ORM\Index(name: 'idx_invoice_organization_issued', columns: ['organization_id', 'issued_at'])]
#[ORM\Index(name: 'idx_invoice_service', columns: ['service_id'])]
#[ORM\Index(name: 'idx_invoice_document', columns: ['document_id'])]
class Invoice implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    #[ORM\Column(name: 'service_id', type: 'uuid', nullable: true)]
    private ?Uuid $serviceId = null;

    #[ORM\Column(name: 'provider_id', type: 'uuid', nullable: true)]
    private ?Uuid $providerId = null;

    #[ORM\Column(name: 'document_id', type: 'uuid', nullable: true)]
    private ?Uuid $documentId = null;

    #[ORM\Column(type: Types::STRING, length: 120, nullable: true)]
    private ?string $number = null;

    #[ORM\Column(name: 'issued_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $issuedAt;

    #[ORM\Column(name: 'total_amount_minor', type: Types::INTEGER)]
    private int $totalAmountMinor;

    #[ORM\Column(type: Types::STRING, length: 3, enumType: Currency::class)]
    private Currency $currency;

    #[ORM\Column(name: 'period_start', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $periodStart = null;

    #[ORM\Column(name: 'period_end', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $periodEnd = null;

    #[ORM\Column(name: 'paid_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $paidAt = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: InvoiceStatus::class)]
    private InvoiceStatus $status = InvoiceStatus::UNKNOWN;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: InvoiceSource::class)]
    private InvoiceSource $source = InvoiceSource::MANUAL;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Uuid $organizationId,
        DateTimeImmutable $issuedAt,
        Money $total,
        InvoiceSource $source = InvoiceSource::MANUAL,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->issuedAt = $issuedAt;
        $this->totalAmountMinor = $total->amountMinor;
        $this->currency = $total->currency;
        $this->source = $source;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
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

    public function getServiceId(): ?Uuid
    {
        return $this->serviceId;
    }

    public function attachToService(?Uuid $serviceId): void
    {
        $this->serviceId = $serviceId;
    }

    public function getProviderId(): ?Uuid
    {
        return $this->providerId;
    }

    public function setProviderId(?Uuid $providerId): void
    {
        $this->providerId = $providerId;
    }

    public function getDocumentId(): ?Uuid
    {
        return $this->documentId;
    }

    public function attachToDocument(?Uuid $documentId): void
    {
        $this->documentId = $documentId;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function setNumber(?string $number): void
    {
        $this->number = null === $number ? null : mb_substr($number, 0, 120);
    }

    public function getIssuedAt(): DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getTotal(): Money
    {
        return Money::of($this->totalAmountMinor, $this->currency);
    }

    public function getTotalAmountMinor(): int
    {
        return $this->totalAmountMinor;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getPeriodStart(): ?DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function getPeriodEnd(): ?DateTimeImmutable
    {
        return $this->periodEnd;
    }

    /**
     * El periodo facturado. Es lo que permite saber si dos facturas del mismo
     * proveedor cubren meses distintos o son la misma repetida.
     */
    public function setBillingPeriod(?DateTimeImmutable $start, ?DateTimeImmutable $end): void
    {
        if (null !== $start && null !== $end && $end < $start) {
            throw new InvalidArgumentException('El fin del periodo no puede ser anterior a su inicio.');
        }

        $this->periodStart = $start;
        $this->periodEnd = $end;
    }

    public function getPaidAt(): ?DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getStatus(): InvoiceStatus
    {
        return $this->status;
    }

    public function getSource(): InvoiceSource
    {
        return $this->source;
    }

    /**
     * Marca la factura como cobrada. Es el único camino a `PAID`: el estado y
     * la fecha de cobro no pueden contradecirse.
     */
    public function markPaid(DateTimeImmutable $paidAt): void
    {
        $this->paidAt = $paidAt;
        $this->status = InvoiceStatus::PAID;
    }

    public function markFailed(): void
    {
        $this->paidAt = null;
        $this->status = InvoiceStatus::FAILED;
    }

    public function markRefunded(): void
    {
        $this->status = InvoiceStatus::REFUNDED;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Clave de deduplicación entre buzones (D-27): la misma factura presente en
     * dos cuentas de correo es una sola factura.
     */
    public function buildDedupKey(): string
    {
        return mb_substr(
            sprintf(
                '%s|%s|%d|%s|%s',
                $this->providerId?->toRfc4122() ?? '',
                $this->number ?? '',
                $this->totalAmountMinor,
                $this->currency->value,
                $this->issuedAt->format('Y-m-d'),
            ),
            0,
            255,
        );
    }
}
