<?php

declare(strict_types=1);

namespace App\Ai\Application\Extraction;

use App\Ai\Domain\Contract\AiProviderException;
use App\Ai\Domain\Contract\DocumentExtractorInterface;
use App\Ai\Domain\ValueObject\ExtractionContext;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Processing\Domain\Dto\ExtractedDocument;
use Psr\Log\LoggerInterface;

/**
 * La escalera de extracción con IA.
 *
 * ## Por qué una cadena y no un `if`
 *
 * Los niveles 5 y 6 son dos casos distintos, pero se parecen mucho: los dos
 * llaman a un modelo, los dos validan el resultado y los dos devuelven un
 * `ExtractedDocument`. La diferencia está en **cuándo** se ejecutan y en **con
 * qué** proveedor. Escribirlo con un `if` en el orquestador pondría esa
 * diferencia en el sitio equivocado; con una cadena, cada extractor declara
 * su propio `supports()` y el orquestador no sabe nada de niveles.
 *
 * ## Cómo se recorre
 *
 * De abajo arriba, en orden de coste: primero el nivel 5, y solo si el
 * contexto ha quedado marcado como `AI_CHEAP` y sigue sin resolver, el nivel 6.
 *
 * La cadena **no** es un `or` de «el primero que dice que sí»»:
 *
 * - Si un extractor dice que no le toca, se pasa al siguiente.
 * - Si un extractor dice que sí pero **falla**, la cadena se detiene: no se
 *   reintenta con el nivel siguiente, porque eso convertiría un error de
 *   proveedor (una clave caducada) en dos llamadas y dos cobros por el mismo
 *   documento. El error se propaga y el pipeline lo registra.
 *
 * ## Un fallo de IA no rompe el pipeline
 *
 * `extract()` propaga la excepción, pero quien la llama (el pipeline) la
 * trata como un documento que se queda en revisión manual. Un proveedor
 * caído no puede impedir que se procesen los demás correos.
 */
final readonly class DocumentExtractorChain
{
    /**
     * @param list<DocumentExtractorInterface> $extractors
     */
    public function __construct(
        private array $extractors,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Ejecuta la escalera sobre un contexto.
     *
     * Devuelve `null` cuando ningún extractor interviene: no hay IA
     * configurada, o la determinista ya resolvió el caso.
     */
    public function extract(ExtractionContext $context): ?ExtractedDocument
    {
        $current = $context;

        foreach ($this->extractors as $extractor) {
            if (!$extractor->supports($current)) {
                continue;
            }

            try {
                $document = $extractor->extract($current);
            } catch (AiProviderException $exception) {
                $this->logger->warning('El extractor de IA ha fallado; el documento queda en revisión.', [
                    'tier' => $extractor->tier()->value,
                    'error' => $exception->getMessage(),
                ]);

                return null;
            }

            $this->logger->info('Documento extraído con IA.', [
                'tier' => $extractor->tier()->value,
                'amount_minor' => $document->amountMinor,
                'provider' => $document->providerName,
            ]);

            // El contexto se actualiza con el nivel conseguido para que el
            // siguiente extractor (el 6) sepa que el 5 ya se usó.
            $current = $this->withTier($current, $extractor->tier());

            // Se intenta el siguiente nivel solo si el actual no ha resuelto
            // el caso y el siguiente sabe escalado desde este nivel.
            if (!$this->isResolved($document)) {
                $next = $this->nextFor($current, $extractor->tier());

                if (null !== $next) {
                    $document = $next;
                }
            }

            return $document;
        }

        return null;
    }

    /**
     * El siguiente extractor de la escalera, si toca.
     */
    private function nextFor(ExtractionContext $context, ExtractionTier $currentTier): ?ExtractedDocument
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->tier() === $currentTier) {
                continue;
            }

            // Solo se escala hacia niveles superiores.
            if (!$this->isHigher($extractor->tier(), $currentTier)) {
                continue;
            }

            if (!$extractor->supports($context)) {
                continue;
            }

            try {
                $document = $extractor->extract($context);
            } catch (AiProviderException $exception) {
                $this->logger->warning('El escalado a IA avanzada ha fallado.', [
                    'tier' => $extractor->tier()->value,
                    'error' => $exception->getMessage(),
                ]);

                return null;
            }

            $this->logger->info('Documento escalado a IA avanzada.', [
                'tier' => $extractor->tier()->value,
                'amount_minor' => $document->amountMinor,
            ]);

            return $document;
        }

        return null;
    }

    /**
     * ¿El documento ya tiene lo mínimo para ser accionable?
     */
    private function isResolved(ExtractedDocument $document): bool
    {
        return $document->isActionable();
    }

    /**
     * ¿Un nivel está por encima de otro?
     *
     * El orden de la escalera es: determinista, parser conocido, IA económica,
     * IA avanzada.
     */
    private function isHigher(ExtractionTier $candidate, ExtractionTier $current): bool
    {
        return match ($candidate) {
            ExtractionTier::AI_ADVANCED => ExtractionTier::AI_CHEAP === $current,
            ExtractionTier::AI_CHEAP => ExtractionTier::DETERMINISTIC === $current
                || ExtractionTier::KNOWN_PARSER === $current,
            default => false,
        };
    }

    /**
     * Copia del contexto con el nivel cambiado.
     */
    private function withTier(ExtractionContext $context, ExtractionTier $tier): ExtractionContext
    {
        return new ExtractionContext(
            text: $context->text,
            deterministic: $context->deterministic,
            metadata: $context->metadata,
            currentTier: $tier,
        );
    }
}
