<?php

declare(strict_types=1);

namespace App\Processing\Application\Extraction;

use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;

use function mb_strtoupper;
use function preg_match_all;

use const PREG_SET_ORDER;

use function trim;

/**
 * Extrae importes y monedas de un texto (ARCHITECTURE.md §13.6).
 *
 * La normalización numérica (`1.234,56` ↔ `1,234.56`) la resuelve `Money`, que
 * ya es la única pieza del proyecto autorizada a interpretar un decimal. Aquí
 * solo se localizan los candidatos y se decide qué moneda tienen.
 *
 * Se prefiere no devolver nada a devolver un importe equivocado: un coste
 * recurrente mal calculado es peor que un descubrimiento que el usuario tiene
 * que completar a mano.
 */
final readonly class AmountParser
{
    /**
     * Exige separador decimal con dos dígitos, o un símbolo de moneda pegado al
     * número. Sin esa restricción, cualquier año o número de factura se
     * confundiría con un importe.
     *
     * El separador de millares admite las dos convenciones (`1.234,56` y
     * `1,234.56`): si solo admitiera el punto, `$1,234.56` se leería como dos
     * importes —`1,23` y `4.56`— y el total elegido sería el equivocado.
     */
    private const string PATTERN = '/(?:(?<pre>€|\$|£|EUR|USD|GBP|CHF)\s*)?(?<num>\d{1,3}(?:[.,\s\x{00A0}]\d{3})*[.,]\d{2}|\d+[.,]\d{2})(?:\s*(?<post>€|\$|£|EUR|USD|GBP|CHF))?/iu';

    /**
     * Todos los importes del texto, en orden de aparición.
     *
     * @return list<Money>
     */
    public function findAll(string $text, Currency $default = Currency::EUR): array
    {
        if ('' === trim($text)) {
            return [];
        }

        if (false === preg_match_all(self::PATTERN, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $amounts = [];

        foreach ($matches as $match) {
            try {
                $amounts[] = Money::fromDecimalString($match['num'], $this->detectCurrency($match, $default));
            } catch (InvalidArgumentException) {
                // Un candidato que no se puede normalizar no es un importe.
            }
        }

        return $amounts;
    }

    /**
     * El importe más probable del texto.
     *
     * Cuando hay varios se elige el **mayor**: en una factura el total siempre
     * es mayor que las líneas de detalle, y el total es lo que se cobra.
     */
    public function findTotal(string $text, Currency $default = Currency::EUR): ?Money
    {
        $amounts = $this->findAll($text, $default);

        if ([] === $amounts) {
            return null;
        }

        $best = $amounts[0];

        foreach ($amounts as $amount) {
            if ($amount->amountMinor > $best->amountMinor) {
                $best = $amount;
            }
        }

        return $best;
    }

    /**
     * @param array<string, string> $match
     */
    /**
     * @param array<int|string, string> $match
     */
    private function detectCurrency(array $match, Currency $default): Currency
    {
        $marker = mb_strtoupper(trim(($match['pre'] ?? '').($match['post'] ?? '')));

        return match ($marker) {
            '€', 'EUR' => Currency::EUR,
            '$', 'USD' => Currency::USD,
            '£', 'GBP' => Currency::GBP,
            'CHF' => Currency::CHF,
            default => $default,
        };
    }
}
