<?php

declare(strict_types=1);

namespace App\Processing\Application\Knowledge;

use function mb_strlen;
use function preg_quote;
use function preg_replace;
use function preg_split;

use const PREG_SPLIT_DELIM_CAPTURE;

use function trim;

/**
 * Convierte un texto variable (un asunto, un nombre de archivo) en una
 * plantilla estable (ARCHITECTURE.md §13.12).
 *
 * El objetivo es que dos correos del mismo proveedor produzcan **la misma**
 * plantilla aunque cambien el número de factura o la fecha:
 *
 * ```text
 * "Factura 2026-01-15 - 45,90 €"  →  /^factura\s+\{fecha\}\s*-\s*\{n\}\s*€$/iu
 * "Factura 2026-02-15 - 45,90 €"  →  la misma plantilla
 * ```
 *
 * La plantilla se guarda como expresión regular porque es lo que permite
 * casar un mensaje nuevo sin comparar cadenas literales, que es exactamente lo
 * que hace `ProviderIdentity` con los patrones globales.
 *
 * Si el texto es tan largo que la plantilla no cabe (o no es estable), se
 * devuelve `null` y no se aprende nada: es preferible no tener conocimiento a
 * tener un patrón que no significa nada.
 */
final class PatternTemplate
{
    /**
     * Longitud máxima del texto de partida. Un asunto más largo que esto no
     * es una plantilla, es un párrafo.
     */
    public const int MAX_SOURCE_LENGTH = 120;

    /**
     * Longitud máxima de la expresión regular resultante, por debajo del
     * límite de la columna.
     */
    public const int MAX_PATTERN_LENGTH = 240;

    /**
     * @return string|null la expresión regular, o `null` si no se puede
     *                     construir una plantilla fiable
     */
    public static function build(string $raw): ?string
    {
        $raw = trim($raw);

        if ('' === $raw || self::MAX_SOURCE_LENGTH < mb_strlen($raw)) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', $raw);
        $normalized = preg_replace('/\d{4}-\d{2}-\d{2}/u', '{fecha}', $normalized ?? '');
        $normalized = preg_replace('/\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}/u', '{fecha}', $normalized ?? '');
        $normalized = preg_replace('/\b\d+(?:[.,]\d+)?\b/u', '{n}', $normalized ?? '');
        $normalized = preg_replace('/(?<![a-z0-9-])(?=[a-z0-9-]*\d)(?=[a-z0-9-]*[a-z])[a-z0-9-]{6,}(?![a-z0-9-])/iu', '{txt}', $normalized ?? '');

        if (null === $normalized || '' === trim($normalized)) {
            return null;
        }

        $parts = preg_split('/(\{fecha\}|\{n\}|\{txt\})/u', $normalized, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (false === $parts) {
            return null;
        }

        $pattern = '';

        foreach ($parts as $part) {
            if ('' === $part) {
                continue;
            }

            $pattern .= match ($part) {
                '{fecha}' => '(?:\d{4}-\d{2}-\d{2}|\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4})',
                '{n}' => '\d+(?:[.,]\d+)?',
                '{txt}' => '[\w\-]+',
                default => preg_quote($part, '/'),
            };
        }

        $pattern = '/^'.$pattern.'$/iu';

        return self::MAX_PATTERN_LENGTH < mb_strlen($pattern) ? null : $pattern;
    }
}
