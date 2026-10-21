<?php

declare(strict_types=1);

namespace App\Ai\Application\Extraction;

use App\Ai\Application\AiUsageRecorder;
use App\Ai\Application\ExtractionValidator;
use App\Ai\Domain\Contract\AiProviderException;
use App\Ai\Domain\Contract\AiProviderInterface;
use App\Ai\Domain\Contract\AiProviderSelectorInterface;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Ai\Domain\ValueObject\ExtractionContext;
use App\Mailbox\Domain\Enum\ExtractionTier;

use function is_string;

use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Nivel 6: extracción con IA avanzada.
 *
 * ## No es el camino por defecto
 *
 * El nivel 6 cuesta bastante más que el 5 y solo entra cuando el 5 no ha
 * bastado. Entrar «porque sí» convertiría cada suscripción difícil en una
 * llamada de primer nivel, que es exactamente el gasto que D-08 quiere evitar.
 *
 * Por eso `supports()` no pregunta «¿es difícil?», sino «¿se ha fallado ya y
 * el caso lo amerita?». Las condiciones son deliberadamente estrechas.
 *
 * ## Las condiciones de escalado
 *
 * | Condición | Por qué |
 * |---|---|
 * | Ya se usó el nivel 5 y no resolvió | No se paga dos veces por lo mismo |
 * | Importe presente pero sin periodicidad | Falta el dato que hace accionable el documento |
 * | Importe presente pero sin proveedor | Igual: sin proveedor no hay coste que atribuir |
 * | Confianza determinista por debajo del umbral | El documento es raro, no solo largo |
 * | Importe ausente y el texto es complejo | Solo si el caso tiene señales de ambigüedad |
 *
 * ## El umbral es configurable
 *
 * El umbral de confianza decide qué documentos se consideran «raros». Va en
 * los parámetros de la aplicación, no en el código, porque es una decisión
 * del operador y cambia según su cartera de suscripciones: una empresa con
 * facturas de papel en PDF y formatos de 2005 necesita un umbral más bajo que
 * otra que solo recibe correos de servicios modernos.
 */
final class AdvancedAiDocumentExtractor extends AbstractAiDocumentExtractor
{
    /**
     * @param float $confidenceThreshold confianza determinista por debajo de la
     *                                   cual el documento se considera raro
     * @param int   $maxTextLength       longitud máxima del texto que se manda;
     *                                   por encima, el caso se considera que
     *                                   necesita un modelo grande
     */
    public function __construct(
        private readonly AiProviderSelectorInterface $selector,
        ValidatorInterface $validator,
        ExtractionValidator $extractionValidator,
        LoggerInterface $logger,
        AiUsageRecorder $usageRecorder,
        private readonly float $confidenceThreshold = 0.3,
        private readonly int $maxTextLength = 8000,
    ) {
        parent::__construct($validator, $extractionValidator, $logger, $usageRecorder);
    }

    public function supports(ExtractionContext $context): bool
    {
        if ('' === trim($context->text)) {
            return false;
        }

        // Sin proveedor avanzado no hay nada que hacer: el nivel 6 no cae al
        // nivel 5, es un escalado.
        if (!$this->selector->has(AiPurpose::ADVANCED_EXTRACTION)) {
            return false;
        }

        // Y el proveedor tiene que ser **de nivel avanzado** de verdad.
        //
        // `has()` solo dice que hay un proveedor configurado para la
        // necesidad, no que sea del nivel correcto. Si el operador ha elegido
        // un proveedor económico para `advanced_extraction`, el nivel 6
        // entraría igual y pagaría una llamada etiquetada como «avanzada» con
        // un modelo barato: el gasto no cambiaría, pero la métrica de
        // escalado (D-36) mentiría y el panel mostraría un nivel 6 que en
        // realidad es un nivel 5.
        if (!$this->hasAdvancedProvider()) {
            return false;
        }

        // Solo se escala desde el nivel 5: si no se ha triedo antes, este no es
        // el peldaño siguiente.
        if (ExtractionTier::AI_CHEAP !== $context->currentTier) {
            return false;
        }

        $amount = $context->knownAmountMinor();
        $provider = $context->knownProvider();

        // Importe y periodicidad: falta lo que hace el documento accionable.
        if (null !== $amount && null === $provider) {
            return true;
        }

        if (null !== $amount && !$this->hasPeriod($context)) {
            return true;
        }

        // Documento raro por confianza baja.
        $confidence = $context->deterministic['confidence'] ?? null;

        if (is_numeric($confidence) && (float) $confidence < $this->confidenceThreshold) {
            return true;
        }

        // Importe ausente y texto muy largo: el extractor determinista no ha
        // podido con un documento de este tamaño, y el caso tiene señales de
        // ambigüedad (un PDF escaneado, un contrato con muchas cláusulas).
        if (null === $amount && mb_strlen($context->text) > $this->maxTextLength) {
            return true;
        }

        return false;
    }

    public function tier(): ExtractionTier
    {
        return ExtractionTier::AI_ADVANCED;
    }

    protected function provider(): AiProviderInterface
    {
        return $this->selector->forPurpose(AiPurpose::ADVANCED_EXTRACTION);
    }

    protected function purpose(): AiPurpose
    {
        return AiPurpose::ADVANCED_EXTRACTION;
    }

    protected function fallbackConfidence(): float
    {
        return 0.75;
    }

    /**
     * ¿El contexto determinista ya tiene periodicidad?
     */
    private function hasPeriod(ExtractionContext $context): bool
    {
        $period = $context->deterministic['billingPeriod'] ?? null;

        return is_string($period) && '' !== $period;
    }

    /**
     * ¿El proveedor elegido para la extracción avanzada es de nivel avanzado?
     *
     * Se resuelve el proveedor real en lugar de fiarse de `has()`: la
     * elección la hace la organización, y nada impide que elija un proveedor
     * económico para una necesidad avanzada. Si lo hace, el nivel 6 no entra
     * (ya lo cubre el nivel 5 con el mismo modelo) y la llamada no se etiqueta
     * con un nivel que no es el real.
     */
    private function hasAdvancedProvider(): bool
    {
        try {
            return AiTier::ADVANCED === $this->selector->forPurpose(AiPurpose::ADVANCED_EXTRACTION)->tier();
        } catch (AiProviderException) {
            return false;
        }
    }
}
