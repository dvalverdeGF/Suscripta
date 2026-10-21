<?php

declare(strict_types=1);

namespace App\Ai\Domain\ValueObject;

use App\Documents\Domain\Enum\DocumentType;
use App\Shared\Domain\ValueObject\BillingPeriod;
use DateTimeImmutable;
use Exception;

use function is_string;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Salida tipada y validada de un modelo de IA (nivel 7).
 *
 * ## Por qué un DTO con Validator y no un `array` con comprobaciones a mano
 *
 * El modelo devuelve JSON y ese JSON **no es de fiar**: puede devolver un
 * importe en euros en vez de céntimos, una fecha de renovación anterior a la
 * de la factura, un periodo que no existe o un importe de -12,99.
 *
 * Con reglas de Symfony Validator se declaran las restricciones **una vez**,
 * con mensajes en español, y se obtienen **todas** a la vez: el usuario ve
 * «el importe es negativo» y «la renovación es anterior a la factura» en el
 * mismo mensaje, en lugar de descubrir los problemas de uno en uno.
 *
 * ## Las reglas que no son de formato
 *
 * `Assert\Positive` y `Assert\LessThanOrEqual` cubren lo mecánico. Las reglas
 * de negocio (importe absurdo, periodo imposible, renovación anterior a la
 * factura) no son restricciones de un campo: son relaciones entre campos, y
 * por eso las comprueba `ExtractionValidator`, no el Validator.
 *
 * ## El importe va en céntimos
 *
 * `amountMinor` es un entero. El modelo devuelve el importe **en la unidad de
 * la moneda** («12,99»), y convertirlo aquí evita que cada extractor
 * interprete los céntimos de forma distinta.
 */
final readonly class AiExtractionResult
{
    public function __construct(
        #[Assert\NotBlank(message: 'Falta el proveedor del servicio.')]
        #[Assert\Length(max: 120, maxMessage: 'El nombre del proveedor es demasiado largo.')]
        public ?string $provider = null,
        #[Assert\Length(max: 120, maxMessage: 'El nombre del servicio es demasiado largo.')]
        public ?string $service = null,
        #[Assert\Length(max: 120, maxMessage: 'El nombre del plan es demasiado largo.')]
        public ?string $plan = null,
        #[Assert\NotNull(message: 'Falta el importe de la suscripción.')]
        #[Assert\Positive(message: 'El importe no puede ser negativo.')]
        #[Assert\LessThanOrEqual(value: 100_000_00, message: 'El importe parece absurdo: revisa que no esté en céntimos de más.')]
        public ?int $amountMinor = null,
        #[Assert\Length(min: 3, max: 3, exactMessage: 'La moneda debe ser un código ISO de tres letras.')]
        public ?string $currency = null,
        #[Assert\Length(max: 60, maxMessage: 'La periodicidad no es válida.')]
        public ?string $billingPeriod = null,
        #[Assert\Length(max: 60, maxMessage: 'El tipo de documento no es válido.')]
        public ?string $documentType = null,
        #[Assert\Length(max: 60, maxMessage: 'El número de factura es demasiado largo.')]
        public ?string $invoiceNumber = null,
        #[Assert\Date(message: 'La fecha de factura no es válida.')]
        public ?string $invoiceDate = null,
        #[Assert\Date(message: 'La fecha de renovación no es válida.')]
        public ?string $renewalDate = null,
        #[Assert\Range(
            min: 0,
            max: 1,
            notInRangeMessage: 'La confianza debe estar entre 0 y 1.',
        )]
        public ?float $confidence = null,
    ) {
    }

    /**
     * Construye el resultado a partir del JSON que devolvió el modelo.
     *
     * Se acepta tanto el importe en céntimos (`amountMinor`) como en unidades de
     * moneda (`amount`), porque el prompt pide `amount` y los modelos no siempre
     * respetan la instrucción de devolver céntimos.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            provider: self::string($data, 'provider'),
            service: self::string($data, 'service'),
            plan: self::string($data, 'plan'),
            amountMinor: self::amountMinor($data),
            currency: self::string($data, 'currency'),
            billingPeriod: self::string($data, 'billingPeriod'),
            documentType: self::string($data, 'documentType'),
            invoiceNumber: self::string($data, 'invoiceNumber'),
            invoiceDate: self::string($data, 'invoiceDate'),
            renewalDate: self::string($data, 'renewalDate'),
            confidence: self::float($data, 'confidence'),
        );
    }

    /**
     * Periodicidad interpretada.
     */
    public function billingPeriodEnum(): ?BillingPeriod
    {
        return BillingPeriod::tryFromLabel($this->billingPeriod);
    }

    /**
     * Tipo de documento interpretado.
     */
    public function documentTypeEnum(): DocumentType
    {
        return DocumentType::tryFrom($this->documentType ?? '') ?? DocumentType::OTHER;
    }

    /**
     * Fecha de factura interpretada.
     */
    public function invoiceDateObject(): ?DateTimeImmutable
    {
        return self::date($this->invoiceDate);
    }

    /**
     * Fecha de renovación interpretada.
     */
    public function renewalDateObject(): ?DateTimeImmutable
    {
        return self::date($this->renewalDate);
    }

    /**
     * @param array<mixed> $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (is_string($value)) {
            $value = trim($value);

            return '' === $value ? null : $value;
        }

        return is_numeric($value) ? (string) $value : null;
    }

    /**
     * @param array<mixed> $data
     */
    private static function float(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = str_replace(',', '.', trim($value));

            if (is_numeric($normalized)) {
                return (float) $normalized;
            }
        }

        return null;
    }

    /**
     * Importe en céntimos.
     *
     * @param array<mixed> $data
     */
    private static function amountMinor(array $data): ?int
    {
        $minor = $data['amountMinor'] ?? null;

        if (is_numeric($minor)) {
            return (int) $minor;
        }

        $amount = $data['amount'] ?? null;

        if (!is_numeric($amount)) {
            return null;
        }

        return (int) round(((float) $amount) * 100);
    }

    private static function date(?string $value): ?DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
