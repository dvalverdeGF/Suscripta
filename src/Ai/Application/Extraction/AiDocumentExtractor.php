<?php

declare(strict_types=1);

namespace App\Ai\Application\Extraction;

use App\Ai\Application\AiUsageRecorder;
use App\Ai\Application\ExtractionValidator;
use App\Ai\Domain\Contract\AiProviderInterface;
use App\Ai\Domain\Contract\AiProviderSelectorInterface;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\ValueObject\ExtractionContext;
use App\Mailbox\Domain\Enum\ExtractionTier;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Nivel 5: extracción con IA económica.
 *
 * Es el primer peldaño que cuesta dinero, y el que cubre la mayoría de los
 * casos: correos de suscripción con importes en formatos que las reglas
 * deterministas no reconocen (IVA desglosado, descuentos, precios con
 * decimales en coma).
 *
 * ## Cuándo entra
 *
 * Cuando la extracción determinista **no ha llegado al importe**. Si ya lo
 * tiene, este nivel no se ejecuta: se paga por una llamada que no aporta.
 *
 * ## Cuándo no entra
 *
 * Cuando no hay proveedor configurado para la necesidad `EXTRACTION`, o
 * cuando la IA está desactivada para la organización (D-08). En ambos casos
 * `supports()` devuelve `false` y el documento se queda en el nivel que tuviera,
 * normalmente en revisión manual.
 */
final class AiDocumentExtractor extends AbstractAiDocumentExtractor
{
    public function __construct(
        private readonly AiProviderSelectorInterface $selector,
        ValidatorInterface $validator,
        ExtractionValidator $extractionValidator,
        LoggerInterface $logger,
        AiUsageRecorder $usageRecorder,
    ) {
        parent::__construct($validator, $extractionValidator, $logger, $usageRecorder);
    }

    public function supports(ExtractionContext $context): bool
    {
        // Sin texto no hay nada que extraer.
        if ('' === trim($context->text)) {
            return false;
        }

        // Si la vía determinista ya tiene el importe, la IA no aporta nada
        // nuevo: el caso ya está resuelto.
        if (null !== $context->knownAmountMinor()) {
            return false;
        }

        return $this->selector->has(AiPurpose::EXTRACTION);
    }

    public function tier(): ExtractionTier
    {
        return ExtractionTier::AI_CHEAP;
    }

    protected function provider(): AiProviderInterface
    {
        return $this->selector->forPurpose(AiPurpose::EXTRACTION);
    }

    protected function purpose(): AiPurpose
    {
        return AiPurpose::EXTRACTION;
    }

    protected function fallbackConfidence(): float
    {
        return 0.65;
    }
}
