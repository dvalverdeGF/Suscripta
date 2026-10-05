<?php

declare(strict_types=1);

namespace App\Shared\UI\Form\DataTransformer;

use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Throwable;

/**
 * Convierte entre `Money` y la cadena decimal que teclea el usuario.
 *
 * El usuario escribe "29,90" o "29.90"; el dominio guarda 2990 céntimos. La
 * conversión pasa por `Money::fromDecimalString`, que no usa `float` en ningún
 * punto (D-09).
 *
 * @implements DataTransformerInterface<Money, string>
 */
final readonly class MoneyToDecimalStringTransformer implements DataTransformerInterface
{
    public function __construct(private Currency $currency)
    {
    }

    public function transform(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (!$value instanceof Money) {
            throw new TransformationFailedException('Se esperaba un importe.');
        }

        return $value->format(false);
    }

    public function reverseTransform(mixed $value): ?Money
    {
        if (null === $value || '' === trim((string) $value)) {
            return null;
        }

        try {
            return Money::fromDecimalString((string) $value, $this->currency);
        } catch (Throwable) {
            throw new TransformationFailedException('El importe no tiene un formato válido.');
        }
    }
}
