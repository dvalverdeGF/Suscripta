<?php

declare(strict_types=1);

namespace App\Ai\Application;

use App\Ai\Application\Dto\AiExtractionOutcome;
use App\Ai\Application\Extraction\DocumentExtractorChain;
use App\Ai\Domain\Contract\AiProviderSelectorInterface;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiSkipReason;
use App\Ai\Domain\ValueObject\ExtractionContext;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Processing\Domain\Dto\ExtractedDocument;
use App\Webhooks\Application\WebhookNotifier;
use App\Webhooks\Domain\Enum\WebhookEvent;

use function implode;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

use function sprintf;
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
 * *un documento mejor, o el motivo por el que no lo hay*.
 *
 * ## El presupuesto se comprueba **antes** de llamar
 *
 * Un presupuesto que se comprueba después de la llamada no es un presupuesto:
 * es un informe. Aquí se comprueba antes, y si se ha agotado, el pipeline se
 * queda con lo que tenía, que es el mismo camino que se recorre cuando no hay
 * IA configurada.
 *
 * ## TresGuardias, tres motivos distintos
 *
 * | Guardia | Motivo | Qué hace el pipeline |
 * |---|---|---|
 * | Presupuesto | `BUDGET_EXHAUSTED` | Aplaza el correo (`DEFERRED`) |
 * | Límite de peticiones | `RATE_LIMITED` | Revisión manual, se reintenta luego |
 * | Texto vacío | `NO_TEXT` | Revisión manual |
 *
 * El presupuesto es el único que **aplaza**: los otros dos casos son fallos
 * o decisiones, no esperas (D-57).
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
        private AiBudgetGuard $budget,
        private AiCallRateLimiter $rateLimiter,
        private AiProviderSelectorInterface $selector,
        private LoggerInterface $logger,
        private ?WebhookNotifier $webhooks = null,
        private ?CacheItemPoolInterface $cache = null,
    ) {
    }

    /**
     * Intenta mejorar el documento con IA.
     *
     * Devuelve el documento mejorado, o el motivo por el que no se ha
     * mejorado. El pipeline decide qué hacer con cada motivo.
     */
    public function enrich(
        ExtractedDocument $deterministic,
        string $haystack,
        ImapMessageHeader $header,
    ): AiExtractionOutcome {
        // 1. Presupuesto. En modo `SOFT` agotado la IA económica sigue
        //    entrando: lo que se apaga es el nivel avanzado, y eso lo decide
        //    el propio extractor avanzado, no esta puerta.
        if (!$this->budget->isAiAvailable()) {
            $this->logger->info('Se omite la extracción con IA: el plan no incluye IA.');

            return AiExtractionOutcome::skipped(AiSkipReason::NOT_IN_PLAN);
        }

        // Avisar de que el presupuesto se ha agotado es un evento, no un
        // registro: se manda a los suscriptores. Va **antes** de la puerta de
        // llamada porque en modo `SOFT` agotado se sigue llamando al modelo
        // económico, y el aviso sigue siendo cierto.
        if ($this->budget->isExhausted()) {
            $this->notifyBudgetExhausted();
        }

        if (!$this->budget->allowsCall()) {
            $this->logger->info('Se omite la extracción con IA: el presupuesto está agotado.', [
                'spent_minor' => $this->budget->spentMinor(),
                'limit_minor' => $this->budget->limitMinor(),
                'mode' => $this->budget->mode()->value,
            ]);

            return AiExtractionOutcome::skipped(AiSkipReason::BUDGET_EXHAUSTED);
        }

        // 2. Límite de peticiones. Se consume el hueco **después** de
        //    comprobar el presupuesto: si el presupuesto está agotado no tiene
        //    sentido gastar un hueco de peticiones que no se va a usar.
        if (!$this->rateLimiter->acquire()) {
            $this->logger->info('Se omite la extracción con IA: límite de peticiones alcanzado.');

            return AiExtractionOutcome::skipped(AiSkipReason::RATE_LIMITED);
        }

        $context = $this->redactor->redact(
            $this->context($deterministic, $haystack, $header),
        );

        if ('' === trim($context->text)) {
            return AiExtractionOutcome::skipped(AiSkipReason::NO_TEXT);
        }

        // 3. ¿Hay proveedor? Sin proveedor elegido para la extracción no se
        //    llama a nadie, y conviene decirlo con su propio motivo: no es un
        //    fallo, es que la organización no ha configurado la IA.
        if (!$this->selector->has(AiPurpose::EXTRACTION)) {
            return AiExtractionOutcome::skipped(AiSkipReason::NOT_CONFIGURED);
        }

        $result = $this->chain->extract($context);

        if (null === $result->document) {
            return AiExtractionOutcome::skipped($result->reason);
        }

        return AiExtractionOutcome::extracted($result->document);
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

    /**
     * Avisa de que el presupuesto de IA del periodo se ha agotado.
     *
     * ## Por qué una vez por periodo y no por correo
     *
     * Este servicio se ejecuta una vez por mensaje. Sin freno, un buzón con
     * cien correos pendientes mandaría cien avisos idénticos el día que se
     * agota el presupuesto, y el suscriptor acabaría ignorándolos todos. El
     * aviso se marca en caché con la clave del periodo, así que se manda una
     * vez al mes y como mucho.
     *
     * Solo se marca si se ha programado alguna entrega: si el aviso cae cuando
     * la organización todavía no tenía suscriptores, el siguiente correo lo
     * vuelve a intentar y quien se suscriba a mitad de mes se entera.
     */
    private function notifyBudgetExhausted(): void
    {
        if (null === $this->webhooks || null === $this->cache) {
            return;
        }

        $organizationId = $this->budget->organizationId();

        if (null === $organizationId) {
            return;
        }

        $period = $this->budget->currentPeriod();
        $key = sprintf('webhooks.ai_budget_exhausted.%s.%s', $organizationId->toRfc4122(), $this->budget->currentPeriodKey());
        $item = $this->cache->getItem($key);

        if ($item->isHit()) {
            return;
        }

        $scheduled = $this->webhooks->notify($organizationId, WebhookEvent::AI_BUDGET_EXHAUSTED, [
            'organizationId' => $organizationId->toRfc4122(),
            'budgetMinor' => $this->budget->limitMinor(),
            'spentMinor' => $this->budget->spentMinor(),
            'periodStart' => $period->from->format('Y-m-d'),
            'periodEnd' => $period->to?->format('Y-m-d'),
        ]);

        if ($scheduled < 1) {
            return;
        }

        $item->set(true);

        if (null !== $period->to) {
            $item->expiresAt($period->to);
        }

        $this->cache->save($item);
    }
}
