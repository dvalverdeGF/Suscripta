<?php

declare(strict_types=1);

namespace App\Processing\Domain\Dto;

use App\Documents\Domain\Enum\DocumentType;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Shared\Domain\ValueObject\BillingPeriod;
use DateTimeImmutable;
use Exception;

use function is_array;
use function is_int;
use function is_numeric;
use function is_string;

/**
 * Resultado normalizado de la extracción (ARCHITECTURE.md §13.6).
 *
 * Es un **DTO, no una entidad**: se persiste como JSON en
 * `Discovery.proposedData` y, más adelante, en `Document`. Su forma evolucionará
 * con los extractores, y una tabla propia obligaría a migrar cada vez que se
 * añade una señal (D-14).
 *
 * Todos los campos son opcionales salvo `tier` y `confidence`: la extracción
 * determinista casi nunca lo rellena todo, y fingir lo contrario sería peor que
 * declarar la incertidumbre.
 */
final readonly class ExtractedDocument
{
    /**
     * @param array<string, mixed> $rawSignals coincidencias concretas (qué regla, qué fragmento)
     */
    public function __construct(
        public ExtractionTier $tier,
        public float $confidence,
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?string $invoiceNumber = null,
        public ?DateTimeImmutable $invoiceDate = null,
        public ?DateTimeImmutable $dueDate = null,
        public ?BillingPeriod $billingPeriod = null,
        public ?string $sender = null,
        public ?string $senderDomain = null,
        public ?string $subject = null,
        public DocumentType $documentType = DocumentType::OTHER,
        public ?string $providerName = null,
        public ?string $serviceName = null,
        public ?string $plan = null,
        public ?DateTimeImmutable $renewalDate = null,
        public array $rawSignals = [],
    ) {
    }

    /**
     * ¿Hay suficiente para proponer algo al usuario?
     *
     * Un importe sin periodicidad no permite calcular un coste recurrente, y un
     * proveedor sin importe no permite decir cuánto cuesta. Se exige lo mínimo
     * para que la propuesta sea útil, no solo plausible.
     */
    public function isActionable(): bool
    {
        return null !== $this->amountMinor
            && null !== $this->providerName
            && null !== $this->billingPeriod
            && $this->billingPeriod->isRecurring();
    }

    /**
     * Reconstruye el documento desde su forma persistida.
     *
     * Es lo que permite que la caché de extracción (D-37) devuelva un resultado
     * ya calculado sin volver a analizar el mismo contenido. Cualquier campo
     * ausente o con un tipo inesperado se degrada a `null` en lugar de fallar:
     * una caché corrupta debe costar una extracción, no un error.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tier: ExtractionTier::tryFrom(self::string($data, 'tier') ?? '') ?? ExtractionTier::DETERMINISTIC,
            confidence: self::float($data, 'confidence') ?? 0.0,
            amountMinor: self::int($data, 'amountMinor'),
            currency: self::string($data, 'currency'),
            invoiceNumber: self::string($data, 'invoiceNumber'),
            invoiceDate: self::date($data, 'invoiceDate'),
            dueDate: self::date($data, 'dueDate'),
            billingPeriod: BillingPeriod::tryFromLabel(self::string($data, 'billingPeriod')),
            sender: self::string($data, 'sender'),
            senderDomain: self::string($data, 'senderDomain'),
            subject: self::string($data, 'subject'),
            documentType: DocumentType::tryFrom(self::string($data, 'documentType') ?? '') ?? DocumentType::OTHER,
            providerName: self::string($data, 'providerName'),
            serviceName: self::string($data, 'serviceName'),
            plan: self::string($data, 'plan'),
            renewalDate: self::date($data, 'renewalDate'),
            rawSignals: self::signals($data, 'rawSignals'),
        );
    }

    /**
     * Copia el documento sustituyendo los datos que pertenecen al mensaje y no
     * al contenido.
     *
     * Un acierto de caché devuelve la extracción de **otro** correo con el mismo
     * cuerpo: el importe y las fechas son los mismos, pero el asunto, el
     * remitente y su dominio son los del mensaje que se está procesando ahora.
     * Conservar los del original haría que la propuesta mostrara datos de un
     * correo que el usuario no ha visto.
     */
    public function withSenderContext(?string $sender, ?string $senderDomain, ?string $subject): self
    {
        return new self(
            tier: $this->tier,
            confidence: $this->confidence,
            amountMinor: $this->amountMinor,
            currency: $this->currency,
            invoiceNumber: $this->invoiceNumber,
            invoiceDate: $this->invoiceDate,
            dueDate: $this->dueDate,
            billingPeriod: $this->billingPeriod,
            sender: $sender,
            senderDomain: $senderDomain,
            subject: $subject,
            documentType: $this->documentType,
            providerName: $this->providerName,
            serviceName: $this->serviceName,
            plan: $this->plan,
            renewalDate: $this->renewalDate,
            rawSignals: $this->rawSignals,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'amountMinor' => $this->amountMinor,
            'currency' => $this->currency,
            'invoiceNumber' => $this->invoiceNumber,
            'invoiceDate' => $this->invoiceDate?->format('Y-m-d'),
            'dueDate' => $this->dueDate?->format('Y-m-d'),
            'billingPeriod' => $this->billingPeriod?->value,
            'sender' => $this->sender,
            'senderDomain' => $this->senderDomain,
            'subject' => $this->subject,
            'documentType' => $this->documentType->value,
            'providerName' => $this->providerName,
            'serviceName' => $this->serviceName,
            'plan' => $this->plan,
            'renewalDate' => $this->renewalDate?->format('Y-m-d'),
            'confidence' => $this->confidence,
            'tier' => $this->tier->value,
            'rawSignals' => $this->rawSignals,
        ];
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }

    /** @param array<string, mixed> $data */
    private static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }

    /** @param array<string, mixed> $data */
    private static function float(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /** @param array<string, mixed> $data */
    private static function date(array $data, string $key): ?DateTimeImmutable
    {
        $value = self::string($data, $key);

        if (null === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function signals(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
