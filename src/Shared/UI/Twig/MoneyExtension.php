<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig;

use App\Ai\Domain\ValueObject\AiCost;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;

use const STR_PAD_LEFT;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Formatea importes guardados como JSON (los `Discovery.proposedData`) sin
 * obligar a la plantilla a reconstruir un `Money`.
 *
 * El dominio guarda dinero en unidades menores y con la moneda aparte
 * (D-09); este filtro es la única traducción de ese par a texto para la vista.
 */
final class MoneyExtension extends AbstractExtension
{
    /** @return list<TwigFilter> */
    public function getFilters(): array
    {
        return [
            new TwigFilter('money', $this->format(...)),
            new TwigFilter('micro_money', $this->formatMicro(...)),
        ];
    }

    public function format(?int $amountMinor, ?string $currency = null): string
    {
        if (null === $amountMinor) {
            return '—';
        }

        $code = null === $currency || '' === $currency ? Currency::EUR : Currency::tryFrom($currency);

        return Money::of($amountMinor, $code ?? Currency::EUR)->format();
    }

    /**
     * Formatea un importe guardado en microcéntimos (D-68).
     *
     * El caso normal es que el importe sea de céntimos y se muestre como
     * cualquier otro dinero. Pero si el importe es **menor que medio céntimo**
     * —lo normal cuando se usan modelos baratos y solo se han hecho unas
     * pocas llamadas— redondear a céntimos lo convertiría en «0,00 €», que
     * parece un error de la pantalla. En ese caso se enseña con cuatro
     * decimales, que es donde está la verdad.
     */
    public function formatMicro(?int $amountMicro): string
    {
        if (null === $amountMicro) {
            return '—';
        }

        $minor = (int) round($amountMicro / AiCost::MICRO_PER_MINOR);

        if (0 === $minor && 0 !== $amountMicro) {
            return $this->formatPrecise($amountMicro);
        }

        return Money::of($minor, Currency::EUR)->format();
    }

    /**
     * Importe en euros con cuatro decimales, para gastos por debajo del
     * céntimo.
     */
    private function formatPrecise(int $amountMicro): string
    {
        $negative = $amountMicro < 0;
        $absolute = abs($amountMicro);

        $euros = intdiv($absolute, AiCost::MICRO_PER_EURO);
        $fraction = intdiv($absolute % AiCost::MICRO_PER_EURO, 100);

        $number = number_format($euros, 0, ',', '.').','.str_pad((string) $fraction, 4, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').$number.' '.Currency::EUR->symbol();
    }
}
