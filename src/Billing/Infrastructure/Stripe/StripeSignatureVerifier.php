<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Stripe;

use function abs;

use App\Billing\Domain\Exception\InvalidSignatureException;

use function explode;
use function hash_equals;
use function hash_hmac;
use function is_numeric;

use SensitiveParameter;

use function str_starts_with;
use function strlen;
use function substr;
use function time;
use function trim;

/**
 * Verifica la cabecera `Stripe-Signature` (Fase 24).
 *
 * ## Qué se comprueba y en qué orden
 *
 * 1. **La forma.** La cabecera es `t=<segundos>,v1=<hex>[,v1=<hex>…]`. Si no
 *    se puede leer, no hay nada que verificar.
 * 2. **La antigüedad.** Una firma válida de hace una semana sigue siendo una
 *    firma válida: si alguien la copia de un log, vale para siempre. Por eso se
 *    rechaza lo que se sale de la tolerancia (5 minutos por defecto).
 * 3. **El HMAC.** `HMAC-SHA256("{t}.{cuerpo}", secreto)` comparado con
 *    `hash_equals`, no con `===`: la comparación de cadenas normales termina en
 *    el primer byte distinto y eso filtra, byte a byte, cuánto se ha acertado.
 *
 * ## Se admiten varias `v1`
 *
 * Durante una rotación del secreto, Stripe firma con las dos versiones. Se
 * acepta cualquiera que cuadre, y no la primera.
 */
final readonly class StripeSignatureVerifier
{
    public function __construct(
        private int $tolerance = 300,
    ) {
    }

    /**
     * @throws InvalidSignatureException si la cabecera no es válida
     */
    public function verify(string $payload, ?string $header, #[SensitiveParameter] string $secret): void
    {
        if ('' === $secret) {
            throw InvalidSignatureException::malformed('no hay secreto configurado para verificar la firma');
        }

        if (null === $header || '' === trim($header)) {
            throw InvalidSignatureException::malformed('falta la cabecera de firma');
        }

        [$timestamp, $signatures] = $this->parse($header);

        $now = time();
        $age = $now - $timestamp;

        if (abs($age) > $this->tolerance) {
            throw InvalidSignatureException::stale($age, $this->tolerance);
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return;
            }
        }

        throw InvalidSignatureException::mismatch();
    }

    /**
     * @return array{0: int, 1: list<string>}
     */
    private function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $part = trim($part);

            if (str_starts_with($part, 't=')) {
                $value = substr($part, 2);

                if (!is_numeric($value)) {
                    throw InvalidSignatureException::malformed('la marca de tiempo no es un número');
                }

                $timestamp = (int) $value;

                continue;
            }

            if (str_starts_with($part, 'v1=')) {
                $value = substr($part, 3);

                if ('' !== $value) {
                    $signatures[] = $value;
                }
            }
        }

        if (null === $timestamp) {
            throw InvalidSignatureException::malformed('falta la marca de tiempo');
        }

        if ([] === $signatures) {
            throw InvalidSignatureException::malformed('no hay ninguna firma v1');
        }

        foreach ($signatures as $signature) {
            if (64 !== strlen($signature)) {
                throw InvalidSignatureException::malformed('la firma no tiene la longitud esperada');
            }
        }

        return [$timestamp, $signatures];
    }
}
