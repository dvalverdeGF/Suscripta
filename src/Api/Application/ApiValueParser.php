<?php

declare(strict_types=1);

namespace App\Api\Application;

use App\Discovery\Domain\Enum\DiscoveryStatus;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Documents\Domain\Enum\DocumentType;
use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertStatus;
use App\Services\Domain\Enum\ServiceStatus;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Exception;
use Symfony\Component\Uid\Uuid;

use function sprintf;
use function strtoupper;
use function trim;

/**
 * Convierte los valores de texto de la API en los tipos del dominio.
 *
 * ## Por qué no deserializar directamente a los tipos del dominio
 *
 * `Money` no se puede construir con `new` (su constructor es privado y exige
 * la unidad mínima de la moneda) y `Currency` es una enumeración: un
 * deserializador que fallara al encontrar «EUR» devolvería un error de
 * sintaxis, no «la moneda EUR no existe». Con un parser propio, el error que
 * ve el integrador dice exactamente qué campo está mal y qué valores valen.
 *
 * Todos los errores que lanza son `InvalidArgumentException` del dominio, que
 * la API traduce a un `422` con el detalle del problema.
 */
final class ApiValueParser
{
    /**
     * Un identificador de recurso.
     */
    public static function uuid(?string $value, string $field): ?Uuid
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        try {
            return Uuid::fromString(trim($value));
        } catch (Exception) {
            throw new InvalidArgumentException(sprintf('El campo «%s» no es un identificador válido.', $field));
        }
    }

    /**
     * Una fecha en ISO-8601.
     */
    public static function date(?string $value, string $field): ?DateTimeImmutable
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable(trim($value));
        } catch (Exception) {
            throw new InvalidArgumentException(sprintf('El campo «%s» no es una fecha válida (se espera ISO-8601).', $field));
        }
    }

    /**
     * Una moneda por su código ISO.
     */
    public static function currency(?string $value): Currency
    {
        if (null === $value || '' === trim($value)) {
            return Currency::EUR;
        }

        $currency = Currency::tryFrom(strtoupper(trim($value)));

        if (null === $currency) {
            throw new InvalidArgumentException(sprintf('La moneda «%s» no existe.', $value));
        }

        return $currency;
    }

    /**
     * Una periodicidad de facturación.
     */
    public static function billingPeriod(?string $value): ?BillingPeriod
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        $period = BillingPeriod::tryFrom(trim($value));

        if (null === $period) {
            throw new InvalidArgumentException(sprintf('La periodicidad «%s» no existe.', $value));
        }

        return $period;
    }

    /**
     * Un importe en la moneda indicada.
     */
    public static function money(?string $value, Currency $currency): ?Money
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        try {
            return Money::fromDecimalString($value, $currency);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException(sprintf('El importe «%s» no es válido.', $value), 0, $exception);
        }
    }

    /**
     * Un estado de servicio.
     */
    public static function serviceStatus(?string $value): ?ServiceStatus
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        $status = ServiceStatus::tryFrom(trim($value));

        if (null === $status) {
            throw new InvalidArgumentException(sprintf('El estado de servicio «%s» no existe.', $value));
        }

        return $status;
    }

    /**
     * Un estado de alerta.
     */
    public static function alertStatus(?string $value): ?AlertStatus
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        $status = AlertStatus::tryFrom(trim($value));

        if (null === $status) {
            throw new InvalidArgumentException(sprintf('El estado de alerta «%s» no existe.', $value));
        }

        return $status;
    }

    /**
     * Una severidad de alerta.
     */
    public static function alertSeverity(?string $value): ?AlertSeverity
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        $severity = AlertSeverity::tryFrom(trim($value));

        if (null === $severity) {
            throw new InvalidArgumentException(sprintf('La severidad «%s» no existe.', $value));
        }

        return $severity;
    }

    /**
     * Un tipo de documento.
     */
    public static function documentType(?string $value): ?DocumentType
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        $type = DocumentType::tryFrom(trim($value));

        if (null === $type) {
            throw new InvalidArgumentException(sprintf('El tipo de documento «%s» no existe.', $value));
        }

        return $type;
    }

    /**
     * Un estado de propuesta.
     */
    public static function discoveryStatus(?string $value): ?DiscoveryStatus
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        $status = DiscoveryStatus::tryFrom(trim($value));

        if (null === $status) {
            throw new InvalidArgumentException(sprintf('El estado de propuesta «%s» no existe.', $value));
        }

        return $status;
    }

    /**
     * Un tipo de propuesta.
     */
    public static function discoveryType(?string $value): ?DiscoveryType
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        $type = DiscoveryType::tryFrom(trim($value));

        if (null === $type) {
            throw new InvalidArgumentException(sprintf('El tipo de propuesta «%s» no existe.', $value));
        }

        return $type;
    }
}
