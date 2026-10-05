<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig;

use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
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
}
