<?php

declare(strict_types=1);

namespace App\Ai\Application\Extraction;

use App\Ai\Application\AiUsageRecorder;
use App\Ai\Application\ExtractionValidator;
use App\Ai\Domain\Contract\AiProviderException;
use App\Ai\Domain\Contract\AiProviderInterface;
use App\Ai\Domain\Contract\DocumentExtractorInterface;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\ValueObject\AiExtractionResult;
use App\Ai\Domain\ValueObject\AiRequest;
use App\Ai\Domain\ValueObject\AiResponse;
use App\Ai\Domain\ValueObject\ExtractionContext;
use App\Documents\Domain\Enum\DocumentType;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Processing\Domain\Dto\ExtractedDocument;
use App\Shared\Domain\ValueObject\BillingPeriod;
use DateTimeImmutable;
use Exception;

use function is_array;
use function is_string;

use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Base común de los extractores que usan un modelo de IA.
 *
 * ## Qué hace el modelo y qué hace el código
 *
 * Al modelo se le pide **leer**: qué proveedor, qué importe, qué periodicidad.
 * Al código se le deja **decidir**: convertir a céntimos, interpretar
 * periodicidades, validar fechas contra reglas de negocio y decidir si el
 * resultado sirve.
 *
 * La frontera importa. Un modelo que devuelve «12,99 €» es útil; un modelo que
 * decide que la suscripción es mensual porque le ha parecido bien, no. Por eso
 * el prompt pide datos y el código los interpreta con las mismas reglas que
 * usa para el resto del sistema (`BillingPeriod::fromLabel`).
 *
 * ## El JSON se pide, pero no se exige
 *
 * Los modelos devuelven JSON con distinta disciplina: algunos lo envuelven en
 * vallas, otros añaden texto antes o después. Se pide JSON «sin más texto»,
 * pero la respuesta se procesa con tolerancia y, si no hay JSON, se busca el
 * primer objeto o array dentro del texto.
 */
abstract class AbstractAiDocumentExtractor implements DocumentExtractorInterface
{
    public function __construct(
        protected readonly ValidatorInterface $validator,
        protected readonly ExtractionValidator $extractionValidator,
        protected readonly LoggerInterface $logger,
        protected readonly AiUsageRecorder $usageRecorder,
    ) {
    }

    /**
     * Proveedor que usa este extractor.
     */
    abstract protected function provider(): AiProviderInterface;

    /**
     * Necesidad que cubre este extractor.
     */
    abstract protected function purpose(): AiPurpose;

    public function extract(ExtractionContext $context): ExtractedDocument
    {
        $provider = $this->provider();

        // La llamada va envuelta en el registrador: así cada llamada —exitosa o
        // fallida— queda anotada en `AiUsage` con su coste (D-36).
        $response = $this->usageRecorder->record(
            $provider,
            $this->purpose(),
            fn (): AiResponse => $provider->complete($this->buildRequest($context)),
        );

        $result = $this->parse($response->data, $response->content);

        $errors = $this->validate($result);

        if ([] !== $errors) {
            $this->logger->warning('La salida de la IA no supera la validación.', [
                'provider' => $this->provider()->key(),
                'tier' => $this->tier()->value,
                'errors' => $errors,
            ]);

            throw AiProviderException::invalidResponse($this->provider()->key(), implode(' ', $errors));
        }

        return $this->toDocument($result, $context);
    }

    /**
     * Valida el resultado: reglas de campo y reglas de negocio.
     *
     * @return list<string>
     */
    protected function validate(AiExtractionResult $result): array
    {
        $violations = $this->validator->validate($result);
        $errors = [];

        foreach ($violations as $violation) {
            $errors[] = (string) $violation->getMessage();
        }

        return [...$errors, ...$this->extractionValidator->validate($result)];
    }

    /**
     * Convierte el JSON del modelo en el DTO tipado.
     *
     * @param array<mixed> $data
     */
    protected function parse(array $data, string $content): AiExtractionResult
    {
        if ([] === $data) {
            $this->logger->warning('El modelo no ha devuelto JSON interpretable.', [
                'provider' => $this->provider()->key(),
                'content' => mb_substr($content, 0, 200),
            ]);

            return new AiExtractionResult();
        }

        return AiExtractionResult::fromArray($data);
    }

    /**
     * Construye la petición al modelo.
     */
    protected function buildRequest(ExtractionContext $context): AiRequest
    {
        return new AiRequest(
            systemPrompt: $this->systemPrompt(),
            userPrompt: $this->userPrompt($context),
        );
    }

    /**
     * Instrucciones del sistema.
     */
    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
            Eres un extractor de datos de facturas y avisos de suscripción.

            Recibes el texto de un correo electrónico, ya limpio, y los datos que
            un extractor determinista ha sido capaz de leer por su cuenta.

            Devuelve **únicamente** un objeto JSON, sin markdown, sin texto antes
            ni después, con estas claves exactas:

            {
              "provider": "nombre de la empresa que cobra",
              "service": "nombre del servicio o producto",
              "plan": "nombre del plan contratado, si aparece",
              "amount": 0.00,
              "currency": "EUR",
              "billingPeriod": "monthly|quarterly|semiannual|annual|weekly|bimonthly|biennial|triennial",
              "documentType": "invoice|receipt|contract|other",
              "invoiceNumber": "número de factura, si aparece",
              "invoiceDate": "AAAA-MM-DD",
              "renewalDate": "AAAA-MM-DD",
              "confidence": 0.0
            }

            Reglas:
            - `amount` es el importe en la unidad de la moneda (12,99 significa 12,99 €), no en céntimos.
            - Si un dato no aparece, deja la clave con valor `null`. No lo inventes.
            - Si el extractor determinista ya ha leído un dato, consérvalo tal cual salvo que veas que es claramente erróneo.
            - `renewalDate` es la fecha de la **próxima** renovación, no la de esta factura.
            - `confidence` es tu confianza entre 0 y 1.
            PROMPT;
    }

    /**
     * Construye el mensaje del usuario con el contenido y el contexto.
     */
    protected function userPrompt(ExtractionContext $context): string
    {
        $parts = [];

        $summary = $context->summary();

        if ('' !== $summary) {
            $parts[] = "Metadatos del mensaje:\n".$summary;
        }

        $known = $this->knownData($context);

        if ('' !== $known) {
            $parts[] = "Datos ya extraídos de forma determinista (úsalos como referencia):\n".$known;
        }

        $parts[] = "Texto del correo:\n---\n".$context->text."\n---";

        return implode("\n\n", $parts);
    }

    /**
     * Datos deterministas que se envían como referencia.
     */
    protected function knownData(ExtractionContext $context): string
    {
        $lines = [];

        foreach ($context->deterministic as $key => $value) {
            if (null === $value || '' === $value || is_array($value)) {
                continue;
            }

            $lines[] = '- '.$key.': '.(is_string($value) ? $value : (string) $value);
        }

        return implode("\n", $lines);
    }

    /**
     * Convierte el resultado validado en el documento normalizado.
     */
    protected function toDocument(AiExtractionResult $result, ExtractionContext $context): ExtractedDocument
    {
        $deterministic = $context->deterministic;
        $period = $result->billingPeriodEnum() ?? $this->deterministicPeriod($deterministic);

        return new ExtractedDocument(
            tier: $this->tier(),
            confidence: $result->confidence ?? $this->fallbackConfidence(),
            amountMinor: $result->amountMinor,
            currency: $result->currency ?? $this->deterministicString($deterministic, 'currency'),
            invoiceNumber: $result->invoiceNumber ?? $this->deterministicString($deterministic, 'invoiceNumber'),
            invoiceDate: $result->invoiceDateObject() ?? $this->deterministicDate($deterministic, 'invoiceDate'),
            dueDate: $this->deterministicDate($deterministic, 'dueDate'),
            billingPeriod: $period,
            sender: $this->deterministicString($deterministic, 'sender'),
            senderDomain: $this->deterministicString($deterministic, 'senderDomain'),
            subject: $this->deterministicString($deterministic, 'subject'),
            documentType: $this->documentType($result, $context),
            providerName: $result->provider ?? $this->deterministicString($deterministic, 'providerName'),
            serviceName: $result->service ?? $this->deterministicString($deterministic, 'serviceName'),
            plan: $result->plan ?? $this->deterministicString($deterministic, 'plan'),
            renewalDate: $result->renewalDateObject() ?? $this->deterministicDate($deterministic, 'renewalDate'),
            rawSignals: [
                'ai_provider' => $this->provider()->key(),
                'ai_tier' => $this->tier()->value,
            ],
        );
    }

    /**
     * Tipo de documento final.
     */
    protected function documentType(AiExtractionResult $result, ExtractionContext $context): DocumentType
    {
        $fromAi = $result->documentTypeEnum();

        if (DocumentType::OTHER !== $fromAi) {
            return $fromAi;
        }

        $fromDeterministic = $this->deterministicString($context->deterministic, 'documentType');

        return DocumentType::tryFrom($fromDeterministic ?? '') ?? DocumentType::OTHER;
    }

    /**
     * Confianza cuando el modelo no la da.
     *
     * Un extractor determinista acierta casi siempre; uno que usa IA y no da
     * confianza debe quedarse por debajo del nivel de la IA, no por encima.
     */
    protected function fallbackConfidence(): float
    {
        return ExtractionTier::AI_ADVANCED === $this->tier() ? 0.75 : 0.65;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function deterministicString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function deterministicDate(array $data, string $key): ?DateTimeImmutable
    {
        $value = $this->deterministicString($data, $key);

        if (null === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function deterministicPeriod(array $data): ?BillingPeriod
    {
        $value = $data['billingPeriod'] ?? null;

        return is_string($value) ? BillingPeriod::tryFromLabel($value) : null;
    }
}
