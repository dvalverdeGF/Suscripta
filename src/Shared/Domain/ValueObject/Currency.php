<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

/**
 * Moneda ISO 4217.
 *
 * `decimals` es el número de decimales con el que se expresa el importe en el
 * mundo real. Los importes se almacenan siempre como enteros en la unidad
 * mínima (céntimos), nunca como float (DECISIONS.md D-09).
 */
enum Currency: string
{
    case EUR = 'EUR';
    case USD = 'USD';
    case GBP = 'GBP';
    case CHF = 'CHF';
    case SEK = 'SEK';
    case NOK = 'NOK';
    case DKK = 'DKK';
    case PLN = 'PLN';
    case CZK = 'CZK';
    case CAD = 'CAD';
    case AUD = 'AUD';
    case JPY = 'JPY';
    case BRL = 'BRL';
    case MXN = 'MXN';
    case INR = 'INR';

    public function decimals(): int
    {
        return match ($this) {
            self::JPY => 0,
            default => 2,
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::EUR => '€',
            self::USD, self::CAD, self::AUD, self::MXN => '$',
            self::GBP => '£',
            self::CHF => 'CHF',
            self::SEK => 'kr',
            self::NOK => 'kr',
            self::DKK => 'kr',
            self::PLN => 'zł',
            self::CZK => 'Kč',
            self::JPY => '¥',
            self::BRL => 'R$',
            self::INR => '₹',
        };
    }

    /**
     * Factor de conversión entre la unidad mínima y la unidad principal.
     */
    public function minorUnitFactor(): int
    {
        return 10 ** $this->decimals();
    }

    public static function tryFromCode(?string $code): ?self
    {
        if (null === $code) {
            return null;
        }

        return self::tryFrom(strtoupper(trim($code)));
    }
}
