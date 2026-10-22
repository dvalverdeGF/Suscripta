<?php

declare(strict_types=1);

namespace App\Discovery\Application\Change;

use Normalizer;

use function preg_replace;
use function round;
use function similar_text;
use function trim;

/**
 * Cuán parecidos son dos nombres de servicio.
 *
 * La comparación de nombres es la pieza más traicionera de la detección de
 * duplicados: «Netflix» y «netflix» son el mismo servicio, pero «Netflix» y
 * «Netflix Business» no. Por eso se normaliza antes de comparar (minúsculas,
 * sin acentos, sin puntuación) y se exige un umbral alto.
 *
 * Se usa `similar_text()` y no una distancia de Levenshtein porque dos nombres
 * iguales con un sufijo pegado —«Spotify» y «Spotify Premium»— tienen mucho
 * en común, y eso ya lo cubre el resto de señales del detector.
 */
final class ServiceSimilarity
{
    /**
     * Normaliza un nombre para poder compararlo.
     *
     * «Acme Cloud — Plan Pro» y «acme cloud plan pro» tienen que acabar
     * siendo la misma cadena: los usuarios escriben los nombres de mil
     * maneras y el detector no puede depender de la puntuación.
     */
    public static function normalize(string $name): string
    {
        $normalized = mb_strtolower(trim($name));

        // Sin diacríticos: «Canción» y «Cancion» son el mismo servicio.
        $normalized = self::stripAccents($normalized);

        // La puntuación y los espacios repetidos solo añaden ruido.
        $normalized = (string) preg_replace('/[^a-z0-9]+/u', ' ', $normalized);

        return trim($normalized);
    }

    /**
     * Similitud entre dos nombres, en porcentaje (0-100).
     */
    public static function similarity(string $a, string $b): int
    {
        $left = self::normalize($a);
        $right = self::normalize($b);

        if ('' === $left || '' === $right) {
            return 0;
        }

        if ($left === $right) {
            return 100;
        }

        similar_text($left, $right, $percent);

        return (int) round($percent);
    }

    private static function stripAccents(string $value): string
    {
        // `ext-intl` es un requisito del proyecto: separar los diacríticos de
        // la letra base deja «canción» y «cancion» en la misma cadena.
        $decomposed = Normalizer::normalize($value, Normalizer::FORM_D);

        if (false === $decomposed) {
            return $value;
        }

        return (string) preg_replace('/\p{Mn}/u', '', $decomposed);
    }
}
