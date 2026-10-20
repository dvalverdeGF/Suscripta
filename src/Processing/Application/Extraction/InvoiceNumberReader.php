<?php

declare(strict_types=1);

namespace App\Processing\Application\Extraction;

use function preg_match;
use function preg_match_all;
use function rtrim;
use function trim;

/**
 * Lector de número de factura (ARCHITECTURE.md §13.6 y §13.7).
 *
 * Vive aparte del extractor por una razón concreta: **el extractor
 * determinista y los parsers de proveedor leen el mismo campo** y se-faced
 * exactamente a los mismos errores. Un solo lector garantiza que los dos
 * niveles coincidan en qué es un número de factura.
 *
 * Dos reglas descartan la mayoría de los falsos positivos:
 *
 * 1. **Un número de factura tiene al menos un dígito.** La etiqueta «Factura»
 *    es alfanumérica y mide más de tres caracteres, así que sin esta regla el
 *    lector se queda con la propia palabra y pierde el dato de verdad.
 * 2. **Un periodo no es un número de factura.** «Factura 2026-10» habla del
 *    mes facturado, no de la factura; el lector lo descarta.
 */
final class InvoiceNumberReader
{
    /**
     * Etiqueta genérica de número de factura.
     *
     * La lookahead exige un dígito en el candidato: es lo que impide que la
     * propia etiqueta («Factura», «Invoice») se capture como número.
     */
    private const string LABEL_PATTERN = '(?:factura|invoice|n[ºo°]|number|número|numero|ref(?:erencia)?|receipt|recibo)';

    /**
     * Un número de factura no es un periodo.
     *
     * El asunto de una factura es casi siempre «Factura 2026-10» o «Factura
     * octubre 2026», y ese `2026-10` no es el número de la factura: es el
     * periodo facturado. Si se aceptara, la propuesta mostraría un número de
     * factura que no existe y el usuario tendría que corregirlo siempre.
     *
     * Se descartan los candidatos que son un año-mes (con o sin separador) y
     * los que son un mes-año, que es el otro formato habitual de periodo.
     */
    private const string PERIOD_LIKE_PATTERN = '/^(?:\d{4}[-\/.]\d{1,2}|\d{1,2}[-\/.]\d{4}|\d{4})$/';

    /**
     * Longitud mínima del candidato: tres caracteres más el primero.
     *
     * Los números de factura reales miden bastante más; el mínimo solo sirve
     * para no devolver fragmentos de dos letras.
     */
    private const int MIN_LENGTH = 4;

    private const int MAX_LENGTH = 30;

    /**
     * Busca un número de factura usando las etiquetas genéricas.
     *
     * @param string $haystack texto completo del documento
     */
    public function find(string $haystack): ?string
    {
        return $this->findWithLabel(self::LABEL_PATTERN, $haystack);
    }

    /**
     * Busca un número de factura usando las etiquetas de un proveedor.
     *
     * `$labelPattern` es un fragmento de expresión regular sin grupo de
     * captura: el número se captura en el grupo 1. Se le aplican las mismas
     * reglas que a las etiquetas genéricas (dígito obligatorio, no es un
     * periodo), de modo que un parser de proveedor no acaba admitiendo
     * candidatos que el extractor genérico rechazaría.
     *
     * @param string $labelPattern patrón de la etiqueta, sin el número
     */
    public function findWithLabel(string $labelPattern, string $haystack): ?string
    {
        $pattern = '/'.$labelPattern.'\s*[:#.\-]?\s*(?=[A-Z0-9\-\/_.]*[0-9])([A-Z0-9][A-Z0-9\-\/_.]{2,29})/iu';

        if (false === preg_match_all($pattern, $haystack, $matches)) {
            return null;
        }

        // Se recorren todos los candidatos y se devuelve el primero que
        // contenga un dígito: el asunto ("Factura OVH 2026-10") suele coincidir
        // antes que el número real, y "OVH" no es un número de factura.
        foreach ($matches[1] as $candidate) {
            $candidate = $this->clean($candidate);

            if (null === $candidate) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Limpia un candidato y descarta los que no pueden ser un número.
     */
    private function clean(string $candidate): ?string
    {
        // El patrón admite puntos y guiones dentro del número, así que
        // también captura la puntuación de la frase («FRA-1.»). Un número
        // de factura no termina en separador.
        $candidate = rtrim(trim($candidate), '.-_/');

        if (mb_strlen($candidate) < self::MIN_LENGTH || mb_strlen($candidate) > self::MAX_LENGTH) {
            return null;
        }

        // Un número de factura tiene al menos un dígito.
        if (1 !== preg_match('/\d/', $candidate)) {
            return null;
        }

        // Un periodo («2026-10») no es un número de factura.
        if (1 === preg_match(self::PERIOD_LIKE_PATTERN, $candidate)) {
            return null;
        }

        return $candidate;
    }
}
