<?php

declare(strict_types=1);

namespace App\Ai\Application;

use App\Ai\Application\Extraction\DocumentExtractorChain;
use App\Ai\Domain\ValueObject\ExtractionContext;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Processing\Domain\Dto\ExtractedDocument;

use function implode;

use Psr\Log\LoggerInterface;

use function trim;

/**
 * Aplica la escalera de IA a un documento que la vía determinista no ha
 * resuelto.
 *
 * ## Por qué este servicio y no llamar a la cadena desde el pipeline
 *
 * El orquestador del pipeline no debería saber cómo se construye un
 * `ExtractionContext`, ni que hay que redactar antes de llamar, ni que existe
 * un presupuesto. Eso son políticas de IA, no política de procesamiento. Este
 * servicio las reúne en un sitio y le da al pipeline una respuesta simple:
 * *un documento mejor, o ninguno*.
 *
 * ## El presupuesto se comprueba **antes** de llamar
 *
 * Un presupuesto que se comprueba después de la llamada no es un presupuesto:
 * es un informe. Aquí se comprueba antes, y si se ha agotado, el pipeline se
 * queda con lo que tenía, que es el mismo camino que se recorre cuando no hay
 * IA configurada.
 *
 * ## Lo que se manda al modelo
 *
 * El texto ya redactado, los metadatos útiles del mensaje y **los datos que
 * la extracción determinista ya ha conseguido**. El modelo no tiene que
 * redescubrir lo que el código ya sabe: solo corregirlo o completarlo.
 */
final readonly class AiExtractionService implements AiExtractionInterface
{
    public function __construct(
        private DocumentExtractorChain $chain,
        private RedactContent $redactor,
        private AiBudget $budget,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Intenta mejorar el documento con IA.
     *
     * Devuelve `null` cuando no hay nada que mejorar, cuando la IA está
     * desactivada, cuando el presupuesto se ha agotado o cuando el modelo ha
     * fallado. El pipeline trata todos esos casos igual: sigue con el
     * documento determinista.
     */
    public function enrich(
        ExtractedDocument $deterministic,
        string $haystack,
        ImapMessageHeader $header,
    ): ?ExtractedDocument {
        if (!$this->budget->allowsCall()) {
            $this->logger->info('Se omite la extracción con IA: el presupuesto está agotado.', [
                'spent_minor' => $this->budget->spentMinor(),
                'limit_minor' => $this->budget->limitMinor(),
            ]);

            return null;
        }

        $context = $this->redactor->redact(
            $this->context($deterministic, $haystack, $header),
        );

        if ('' === trim($context->text)) {
            return null;
        }

        return $this->chain->extract($context);
    }

    /**
     * Construye el contexto con lo que ya se sabe.
     */
    private function context(
        ExtractedDocument $document,
        string $haystack,
        ImapMessageHeader $header,
    ): ExtractionContext {
        return new ExtractionContext(
            text: $haystack,
            deterministic: $this->deterministicData($document),
            metadata: $this->metadata($header),
            currentTier: $document->tier,
        );
    }

    /**
     * Datos que la vía determinista ya ha leído.
     *
     * Se envían como **contexto**, no como resultado: el modelo puede
     * corregirlos, pero la validación decide qué se guarda.
     *
     * @return array<string, mixed>
     */
    private function deterministicData(ExtractedDocument $document): array
    {
        return [
            'amountMinor' => $document->amountMinor,
            'currency' => $document->currency,
            'invoiceNumber' => $document->invoiceNumber,
            'invoiceDate' => $document->invoiceDate?->format('Y-m-d'),
            'dueDate' => $document->dueDate?->format('Y-m-d'),
            'billingPeriod' => $document->billingPeriod?->value,
            'providerName' => $document->providerName,
            'serviceName' => $document->serviceName,
            'plan' => $document->plan,
            'renewalDate' => $document->renewalDate?->format('Y-m-d'),
            'documentType' => $document->documentType->value,
            'confidence' => $document->confidence,
            'sender' => $document->sender,
            'senderDomain' => $document->senderDomain,
            'subject' => $document->subject,
        ];
    }

    /**
     * Cabeceras útiles para el prompt.
     *
     * Solo las que ayudan a identificar el documento. El resto de cabeceras
     * (IP de origen, identificadores de rastreo) no se mandan: no aportan nada
     * y son datos que no deberían salir de la organización (D-08).
     *
     * @return array<string, string>
     */
    private function metadata(ImapMessageHeader $header): array
    {
        $metadata = [
            'subject' => $header->subject,
        ];

        if (null !== $header->fromAddress) {
            $metadata['from'] = $header->fromAddress;
        }

        if (null !== $header->fromName) {
            $metadata['fromName'] = $header->fromName;
        }

        if (null !== $domain = $header->senderDomain()) {
            $metadata['senderDomain'] = $domain;
        }

        if (null !== $header->receivedAt) {
            $metadata['receivedAt'] = $header->receivedAt->format('Y-m-d H:i');
        }

        if ($header->hasAttachments()) {
            $metadata['attachments'] = implode(', ', $header->attachmentNames);
        }

        return $metadata;
    }
}
