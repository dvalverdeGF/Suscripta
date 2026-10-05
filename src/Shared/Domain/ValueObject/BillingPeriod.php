<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;

use function in_array;

use const PHP_INT_MAX;

use function sprintf;

/**
 * Periodicidad de cobro de un servicio.
 *
 * `months` es la duración nominal en meses (null para periodos no expresables
 * en meses). `days` es la duración en días, usada para el cálculo de la próxima
 * renovación. Ver ARCHITECTURE.md §10.
 */
enum BillingPeriod: string
{
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
    case BIMONTHLY = 'bimonthly';
    case QUARTERLY = 'quarterly';
    case SEMIANNUAL = 'semiannual';
    case ANNUAL = 'annual';
    case BIENNIAL = 'biennial';
    case TRIENNIAL = 'triennial';
    case ONE_TIME = 'one_time';
    case CUSTOM = 'custom';
    case UNKNOWN = 'unknown';

    public function months(): ?int
    {
        return match ($this) {
            self::MONTHLY => 1,
            self::BIMONTHLY => 2,
            self::QUARTERLY => 3,
            self::SEMIANNUAL => 6,
            self::ANNUAL => 12,
            self::BIENNIAL => 24,
            self::TRIENNIAL => 36,
            default => null,
        };
    }

    public function days(): ?int
    {
        return match ($this) {
            self::WEEKLY => 7,
            self::MONTHLY => 30,
            self::BIMONTHLY => 61,
            self::QUARTERLY => 91,
            self::SEMIANNUAL => 182,
            self::ANNUAL => 365,
            self::BIENNIAL => 730,
            self::TRIENNIAL => 1095,
            default => null,
        };
    }

    public function isRecurring(): bool
    {
        return !in_array($this, [self::ONE_TIME, self::UNKNOWN], true);
    }

    /**
     * Número de cobros al año. Null si no es recurrente o es personalizado.
     */
    public function occurrencesPerYear(): ?float
    {
        return match ($this) {
            self::WEEKLY => 52.0,
            self::MONTHLY => 12.0,
            self::BIMONTHLY => 6.0,
            self::QUARTERLY => 4.0,
            self::SEMIANNUAL => 2.0,
            self::ANNUAL => 1.0,
            self::BIENNIAL => 0.5,
            self::TRIENNIAL => 1 / 3,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::WEEKLY => 'Semanal',
            self::MONTHLY => 'Mensual',
            self::BIMONTHLY => 'Bimestral',
            self::QUARTERLY => 'Trimestral',
            self::SEMIANNUAL => 'Semestral',
            self::ANNUAL => 'Anual',
            self::BIENNIAL => 'Bienal',
            self::TRIENNIAL => 'Trienal',
            self::ONE_TIME => 'Pago único',
            self::CUSTOM => 'Personalizado',
            self::UNKNOWN => 'Desconocido',
        };
    }

    /**
     * Deduce la periodicidad a partir de la distancia en días entre dos cobros.
     * Devuelve null si la distancia no encaja con ninguna periodicidad conocida.
     */
    public static function fromDayDistance(int $days): ?self
    {
        if ($days <= 0) {
            return null;
        }

        // Los enums no pueden ser claves de array en PHP: se usa una lista de pares.
        $candidates = [
            [self::WEEKLY, 7],
            [self::MONTHLY, 30],
            [self::BIMONTHLY, 61],
            [self::QUARTERLY, 91],
            [self::SEMIANNUAL, 182],
            [self::ANNUAL, 365],
            [self::BIENNIAL, 730],
            [self::TRIENNIAL, 1095],
        ];

        $best = null;
        $bestDelta = PHP_INT_MAX;

        foreach ($candidates as [$period, $expected]) {
            $delta = abs($days - $expected);
            // Tolerancia del 15 %: los meses no tienen todos 30 días.
            if ($delta <= max(2, (int) round($expected * 0.15)) && $delta < $bestDelta) {
                $best = $period;
                $bestDelta = $delta;
            }
        }

        return $best;
    }

    /**
     * Normaliza etiquetas habituales en facturas ("1 month", "anual", "yearly").
     */
    public static function fromLabel(string $label): ?self
    {
        $normalized = mb_strtolower(trim($label));

        return match (true) {
            '' === $normalized => null,
            str_contains($normalized, 'week') || str_contains($normalized, 'semana') => self::WEEKLY,
            str_contains($normalized, 'bimonth') || str_contains($normalized, 'bimestral') => self::BIMONTHLY,
            str_contains($normalized, 'quarter') || str_contains($normalized, 'trimestral') => self::QUARTERLY,
            str_contains($normalized, 'semi') || str_contains($normalized, 'half') => self::SEMIANNUAL,
            str_contains($normalized, 'bienn') || str_contains($normalized, '2 year') => self::BIENNIAL,
            str_contains($normalized, 'trienn') || str_contains($normalized, '3 year') => self::TRIENNIAL,
            str_contains($normalized, 'year') || str_contains($normalized, 'anual') || str_contains($normalized, 'annum') => self::ANNUAL,
            str_contains($normalized, 'month') || str_contains($normalized, 'mensual') => self::MONTHLY,
            str_contains($normalized, 'one time') || str_contains($normalized, 'one-time') || str_contains($normalized, 'unico') => self::ONE_TIME,
            default => null,
        };
    }

    public static function tryFromLabel(?string $label): ?self
    {
        return null === $label ? null : self::fromLabel($label);
    }

    public static function fromMonths(int $months): self
    {
        return match ($months) {
            1 => self::MONTHLY,
            2 => self::BIMONTHLY,
            3 => self::QUARTERLY,
            6 => self::SEMIANNUAL,
            12 => self::ANNUAL,
            24 => self::BIENNIAL,
            36 => self::TRIENNIAL,
            default => throw new InvalidArgumentException(sprintf('Periodicidad no soportada: %d meses.', $months)),
        };
    }
}
