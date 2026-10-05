<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;

use function sprintf;

use const STR_PAD_LEFT;

use Stringable;

use function strlen;

/**
 * Importe monetario expresado en la unidad mínima de su moneda.
 *
 * Nunca se usa `float` para dinero (DECISIONS.md D-09).
 */
final readonly class Money implements Stringable
{
    private function __construct(
        public int $amountMinor,
        public Currency $currency,
    ) {
    }

    public static function of(int $amountMinor, Currency $currency): self
    {
        return new self($amountMinor, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Construye un importe a partir de una representación decimal en texto
     * ("29,90", "29.90", "1.234,56"). No usa `float` en ningún punto.
     */
    public static function fromDecimalString(string $value, Currency $currency): self
    {
        $normalized = self::normalizeDecimalString($value);

        if ('' === $normalized) {
            throw new InvalidArgumentException(sprintf('Importe no válido: "%s".', $value));
        }

        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '+-');

        [$integer, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');

        $decimals = $currency->decimals();
        $fraction = substr(str_pad($fraction, $decimals, '0'), 0, $decimals);

        $minor = (int) $integer * $currency->minorUnitFactor() + (int) ($fraction ?: '0');

        return new self($negative ? -$minor : $minor, $currency);
    }

    /**
     * Acepta "1.234,56" y "1,234.56" y devuelve "1234.56".
     */
    private static function normalizeDecimalString(string $value): string
    {
        $value = preg_replace('/[^\d,.\-]/u', '', trim($value)) ?? '';

        if ('' === $value) {
            return '';
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if (false !== $lastComma && false !== $lastDot) {
            // El separador decimal es el que aparece más a la derecha.
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
            $thousandsSeparator = ',' === $decimalSeparator ? '.' : ',';
        } elseif (false !== $lastComma) {
            // Solo comas: decimal si hay 1-2 dígitos detrás, si no es separador de miles.
            $decimalSeparator = (strlen($value) - $lastComma - 1) <= 2 ? ',' : '';
            $thousandsSeparator = ',' === $decimalSeparator ? '' : ',';
        } elseif (false !== $lastDot) {
            $decimalSeparator = (strlen($value) - $lastDot - 1) <= 2 ? '.' : '';
            $thousandsSeparator = '.' === $decimalSeparator ? '' : '.';
        } else {
            $decimalSeparator = '';
            $thousandsSeparator = '';
        }

        if ('' !== $thousandsSeparator) {
            $value = str_replace($thousandsSeparator, '', $value);
        }

        if (',' === $decimalSeparator) {
            $value = str_replace(',', '.', $value);
        }

        return $value;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor - $other->amountMinor, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->amountMinor * $factor, $this->currency);
    }

    public function isZero(): bool
    {
        return 0 === $this->amountMinor;
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amountMinor === $other->amountMinor;
    }

    /**
     * Diferencia relativa respecto a otro importe, como fracción (0.1 = +10 %).
     * Devuelve null si el importe de referencia es cero.
     */
    public function relativeDifferenceTo(self $other): ?float
    {
        $this->assertSameCurrency($other);

        if (0 === $other->amountMinor) {
            return null;
        }

        return ($this->amountMinor - $other->amountMinor) / abs($other->amountMinor);
    }

    public function format(bool $withSymbol = true): string
    {
        $decimals = $this->currency->decimals();
        $absolute = abs($this->amountMinor);
        $factor = $this->currency->minorUnitFactor();

        $integerPart = intdiv($absolute, $factor);
        $fractionPart = $absolute % $factor;

        $number = number_format($integerPart, 0, ',', '.');
        if ($decimals > 0) {
            $number .= ','.str_pad((string) $fractionPart, $decimals, '0', STR_PAD_LEFT);
        }

        $sign = $this->amountMinor < 0 ? '-' : '';

        if (!$withSymbol) {
            return $sign.$number;
        }

        return $sign.$number.' '.$this->currency->symbol();
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(sprintf('No se pueden operar importes en %s y %s.', $this->currency->value, $other->currency->value));
        }
    }
}
