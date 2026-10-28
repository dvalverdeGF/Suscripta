<?php

declare(strict_types=1);

namespace App\Webhooks\Domain\Service;

use function hash_hmac;
use function sprintf;

/**
 * Firma de las entregas de webhook (Fase 20).
 *
 * ## El formato
 *
 * Se firma `"{timestamp}.{cuerpo}"` con HMAC-SHA-256 y el secreto del
 * suscriptor. El resultado viaja en la cabecera:
 *
 * ```
 * X-Suscripta-Signature: t=1759000000,v1=5f2c…
 * ```
 *
 * Es el mismo esquema que usa Stripe, y no es por imitar: incluir el
 * **timestamp dentro de lo firmado** es lo que permite al receptor rechazar
 * una entrega vieja reenviada por un tercero. Si solo se firmara el cuerpo, una
 * petición capturada valdría para siempre.
 *
 * ## Lo que el receptor tiene que hacer
 *
 * 1. Recalcular el HMAC sobre `"{t}.{cuerpo}"` con su secreto.
 * 2. Comparar en **tiempo constante** (`hash_equals`), nunca con `===`.
 * 3. Rechazar si `t` se aleja más de cinco minutos de su reloj.
 *
 * Los pasos están escritos en `docs/api/webhooks.md` con ejemplo en tres
 * lenguajes, porque una firma mal verificada es peor que ninguna.
 */
final readonly class WebhookSigner
{
    public const string HEADER = 'X-Suscripta-Signature';

    public function sign(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', sprintf('%d.%s', $timestamp, $body), $secret);
    }

    /**
     * Cabecera completa, lista para enviar.
     */
    public function header(string $secret, int $timestamp, string $body): string
    {
        return sprintf('t=%d,v1=%s', $timestamp, $this->sign($secret, $timestamp, $body));
    }

    /**
     * Comprobación de una firma recibida.
     *
     * Existe para el **panel** (probar un suscriptor) y para los tests de
     * integración del propio Suscripta; el receptor implementa la suya. Se
     * escribe aquí para que la comparación en tiempo constante no se olvide en
     * el sitio donde más fácil es olvidarla.
     */
    public function verify(string $secret, string $header, string $body, int $now, int $toleranceSeconds = 300): bool
    {
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);

            if ('t' === $key) {
                $timestamp = (int) $value;
            } elseif ('v1' === $key) {
                $signature = $value;
            }
        }

        if (null === $timestamp || null === $signature) {
            return false;
        }

        if (abs($now - $timestamp) > $toleranceSeconds) {
            return false;
        }

        return hash_equals($this->sign($secret, $timestamp, $body), $signature);
    }
}
