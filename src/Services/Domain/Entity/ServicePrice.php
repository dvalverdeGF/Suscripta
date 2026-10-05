<?php

declare(strict_types=1);

namespace App\Services\Domain\Entity;

use App\Services\Domain\Enum\ServiceSource;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fila del historial de precios de un servicio (D-13).
 *
 * El precio vigente es la fila con `validTo IS NULL`. Un cambio de precio cierra
 * la fila anterior e inserta una nueva; nunca se muta el importe de una fila
 * existente. Así "este servicio ha subido un 19,8 % en el último año" es una
 * consulta, no una reconstrucción frágil a partir de eventos.
 */
#[ORM\Entity]
#[ORM\Table(name: 'service_price')]
#[ORM\Index(name: 'idx_service_price_service_valid', columns: ['service_id', 'valid_from', 'valid_to'])]
class ServicePrice
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Service::class, inversedBy: 'prices')]
    #[ORM\JoinColumn(name: 'service_id', nullable: false, onDelete: 'CASCADE')]
    private Service $service;

    #[ORM\Column(name: 'amount_minor', type: Types::INTEGER)]
    private int $amountMinor;

    #[ORM\Column(type: Types::STRING, length: 3, enumType: Currency::class)]
    private Currency $currency;

    #[ORM\Column(name: 'valid_from', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $validFrom;

    #[ORM\Column(name: 'valid_to', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $validTo = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: ServiceSource::class)]
    private ServiceSource $source;

    #[ORM\Column(name: 'invoice_id', type: 'uuid', nullable: true)]
    private ?Uuid $invoiceId = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        Service $service,
        Money $amount,
        DateTimeImmutable $validFrom,
        ServiceSource $source = ServiceSource::MANUAL,
        ?string $note = null,
        ?Uuid $invoiceId = null,
    ) {
        $this->id = Uuid::v7();
        $this->service = $service;
        $this->amountMinor = $amount->amountMinor;
        $this->currency = $amount->currency;
        $this->validFrom = $validFrom;
        $this->source = $source;
        $this->note = $note;
        $this->invoiceId = $invoiceId;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getService(): Service
    {
        return $this->service;
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

    public function getValidFrom(): DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getValidTo(): ?DateTimeImmutable
    {
        return $this->validTo;
    }

    public function isCurrent(): bool
    {
        return null === $this->validTo;
    }

    public function getSource(): ServiceSource
    {
        return $this->source;
    }

    public function getInvoiceId(): ?Uuid
    {
        return $this->invoiceId;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Cierra la fila. Solo se puede cerrar una fila abierta y nunca antes de su
     * propia fecha de entrada en vigor.
     */
    public function close(DateTimeImmutable $validTo): void
    {
        if (null !== $this->validTo) {
            throw new InvalidArgumentException('Esta fila de precio ya está cerrada.');
        }

        if ($validTo < $this->validFrom) {
            throw new InvalidArgumentException('El precio nuevo no puede entrar en vigor antes que el vigente. Si necesitas registrar precios anteriores, ajusta primero la fecha de alta del servicio.');
        }

        $this->validTo = $validTo;
    }

    /**
     * ¿Estaba este precio vigente en una fecha concreta?
     */
    public function covers(DateTimeImmutable $date): bool
    {
        if ($date < $this->validFrom) {
            return false;
        }

        return null === $this->validTo || $date < $this->validTo;
    }
}
