<?php

declare(strict_types=1);

namespace App\Ai\Application;

use App\Ai\Domain\ValueObject\ExtractionContext;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Prepara el texto de un correo antes de mandarlo a un modelo de IA.
 *
 * ## Qué se quita
 *
 * | Qué | Por qué |
 * |---|---|
 * | HTML | Ruido; el modelo lee peor el HTML que el texto plano |
 * | Scripts y estilos | Contenido ejecutable, irrelevante |
 * | Hilos citados | El texto anterior de la conversación no es el documento |
 * | Firmas y bloques de contacto | Datos personales de terceros (D-08) |
 * | Avisos legales y pies | Texto boilerplate que confunde al modelo |
 * | Enlaces de seguimiento | Ruido y datos que no hacen falta |
 * | Imágenes con alt | Un `alt` no aporta nada a la extracción |
 *
 * ## Qué no se quita
 *
 * El cuerpo del mensaje y la tabla de importes, que es justo lo que se quiere
 * extraer. El redactado es **conservador**: ante la duda, se deja el texto.
 *
 * ## Por qué el recorte de longitud
 *
 * Un correo de 40 000 caracteres cuesta tokens que nadie va a pagar por algo
 * que no aporta. El límite por defecto corta por el final, que es donde está la
 * información de la factura, y no por el principio (donde está el saludo y la
 * presentación). Un correo con el cuerpo al principio y un pie larguísimo
 * perdería el final, que es lo peor que se puede perder.
 */
final readonly class RedactContent
{
    private const int DEFAULT_MAX_LENGTH = 8000;

    /**
     * Bloques que se eliminan por completo.
     *
     * @var list<string>
     */
    private const array STRIP_ELEMENTS = [
        'script',
        'style',
        'head',
        'noscript',
        'iframe',
        'svg',
    ];

    /**
     * Patrones de bloques que se eliminan por su contenido.
     *
     * Se buscan en el texto ya plano, así que funcionan igual en correos en
     * HTML y en texto.
     *
     * @var list<string>
     */
    private const array NOISE_PATTERNS = [
        // Hilo citado: líneas que empiezan por «>» en texto plano.
        '/^\s*>.+$/m',
        // Aviso legal / confidencialidad.
        '/(?:aviso\s+legal|confidencialidad|this\s+(?:message|email)\s+is\s+confidential)[^\n]*(?:\n[^\n]*){0,6}/i',
        // Firmas y bloques de contacto.
        '/(?:enviado\s+desde\s+mi\s+(?:m[oó]vil|iphone|ipad)|sent\s+from\s+my\s+(?:iphone|ipad|android))[^\n]*/i',
        '/(?:este\s+mensaje\s+y\s+sus\s+archivos\s+adjuntos\s+contienen)[^\n]*/i',
        // Pie de página con redes o copyright.
        '/^(?:©|copyright)[^\n]*/im',
        // Cancelación de suscripción: ruido para la extracción, pero la acción
        // la detecta otro proceso.
        '/^(?:si\s+no\s+deseas\s+recibir|para\s+dejar\s+de\s+recibir|unsubscribe|to\s+unsubscribe)[^\n]*/im',
    ];

    public function __construct(
        private readonly int $maxLength = self::DEFAULT_MAX_LENGTH,
    ) {
    }

    /**
     * Redacta el contexto y lo devuelve listo para el modelo.
     */
    public function redact(ExtractionContext $context): ExtractionContext
    {
        $text = $this->stripHtml($context->text);
        $text = $this->stripNoise($text);
        $text = $this->collapseWhitespace($text);
        $text = $this->truncate($text, $this->maxLength);

        return new ExtractionContext(
            text: $text,
            deterministic: $context->deterministic,
            metadata: $context->metadata,
            currentTier: $context->currentTier,
        );
    }

    /**
     * Convierte el HTML en texto plano.
     */
    public function stripHtml(string $html): string
    {
        $text = $html;

        foreach (self::STRIP_ELEMENTS as $tag) {
            $text = preg_replace(
                '#<'.$tag.'\b[^>]*>.*?</'.$tag.'>#is',
                ' ',
                $text,
            ) ?? $text;
        }

        // Los comentarios HTML a veces traen el texto citado del hilo.
        $text = preg_replace('#<!--.*?-->#s', ' ', $text) ?? $text;

        // Los <br> y los cierres de bloque son separadores de línea: sin esto
        // «importe» y «periodo» acabarían en la misma palabra.
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(p|div|tr|li|h[1-6]|table)>#i', "\n", $text) ?? $text;
        $text = preg_replace('#<[^>]+>#', ' ', $text) ?? $text;

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Elimina bloques de ruido del texto plano.
     */
    public function stripNoise(string $text): string
    {
        foreach (self::NOISE_PATTERNS as $pattern) {
            $text = preg_replace($pattern, ' ', $text) ?? $text;
        }

        return $text;
    }

    /**
     * Colapsa espacios y saltos de línea redundantes.
     */
    public function collapseWhitespace(string $text): string
    {
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Recorta el texto al límite, cuidando no partir una línea.
     */
    public function truncate(string $text, int $maxLength): string
    {
        if ($maxLength <= 0 || mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $cut = mb_substr($text, 0, $maxLength);
        $lastNewline = mb_strrpos($cut, "\n");

        if (false !== $lastNewline && $lastNewline > $maxLength / 2) {
            $cut = mb_substr($cut, 0, $lastNewline);
        }

        return $cut."\n[texto recortado]";
    }
}
