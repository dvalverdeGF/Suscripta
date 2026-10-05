<?php

declare(strict_types=1);

namespace App\Processing\Application\Billing;

use App\Catalog\Domain\Enum\ProviderIdentityType;
use App\Catalog\Domain\Repository\ProviderIdentityRepositoryInterface;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Processing\Domain\Billing\BillingClassifierInterface;
use App\Processing\Domain\Dto\BillingScoreResult;
use App\Processing\Domain\Dto\BillingSignal;

use function in_array;
use function max;
use function mb_strtolower;
use function mb_substr;
use function min;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function trim;

/**
 * Filtro determinista del nivel 2 (ARCHITECTURE.md §13.4).
 *
 * Es el guardián de coste del pipeline: lo que no pasa por aquí **no se
 * descarga**, así que un buzón con veinte mil correos no cuesta veinte mil
 * análisis. Por eso el score combina señales independientes en lugar de buscar
 * palabras clave: un boletín con la palabra "invoice" en el asunto no es una
 * factura, y una factura de un proveedor conocido con el asunto en otro idioma
 * sí lo es.
 *
 * El resultado es siempre explicable: cada punto viene de una `BillingSignal`
 * con su peso y su detalle, y se guarda en `EmailMessage.billingReasons`.
 */
final readonly class BillingScoreCalculator implements BillingClassifierInterface
{
    /**
     * Importe con separador decimal y, opcionalmente, símbolo de moneda.
     * Acepta `1.234,56 €`, `€1,234.56`, `29.90 EUR` y `29,90€`.
     */
    private const string AMOUNT_PATTERN = '/(?:(?:EUR|USD|GBP|CHF)\s*)?\d{1,3}(?:[.,\s]\d{3})*[.,]\d{2}\s*(?:€|\$|£|EUR|USD|GBP|CHF)?/iu';

    public function __construct(
        private BillingScoreWeights $weights,
        private ProviderIdentityRepositoryInterface $providerIdentities,
    ) {
    }

    /**
     * @param string|null $bodyText extracto del cuerpo, si ya se ha descargado.
     *                              En el primer pase es `null` y el score se
     *                              calcula solo con metadatos.
     */
    public function score(ImapMessageHeader $header, ?string $bodyText = null): BillingScoreResult
    {
        $signals = [];
        $subject = mb_strtolower($header->subject);
        $localPart = $header->senderLocalPart() ?? '';
        $domain = $header->senderDomain() ?? '';
        $haystack = $subject.' '.mb_strtolower($bodyText ?? '');

        if ([] !== $this->matchedKeywords($subject, $this->weights->subjectKeywords)) {
            $signals[] = new BillingSignal(
                'subject_keyword',
                $this->weights->subjectKeyword,
                sprintf('asunto: %s', implode(', ', $this->matchedKeywords($subject, $this->weights->subjectKeywords))),
            );
        }

        $provider = $this->resolveProvider($domain, $header->fromAddress);

        if (null !== $provider) {
            $signals[] = new BillingSignal('known_provider', $this->weights->knownProvider, $provider);
        }

        if ($this->hasPdfAttachment($header)) {
            $signals[] = new BillingSignal('pdf_attachment', $this->weights->pdfAttachment, $this->pdfName($header));
        }

        if (1 === preg_match(self::AMOUNT_PATTERN, $haystack)) {
            $signals[] = new BillingSignal('detectable_amount', $this->weights->detectableAmount);
        }

        $renewal = $this->matchedKeywords($haystack, $this->weights->renewalKeywords);

        if ([] !== $renewal) {
            $signals[] = new BillingSignal('renewal_language', $this->weights->renewalLanguage, implode(', ', $renewal));
        }

        if ('' !== $localPart && in_array($localPart, $this->weights->billingLocalParts, true)) {
            $signals[] = new BillingSignal('billing_local_part', $this->weights->billingLocalPart, $localPart);
        }

        $attachmentMatch = $this->matchedAttachmentName($header);

        if (null !== $attachmentMatch) {
            $signals[] = new BillingSignal('invoice_attachment_name', $this->weights->invoiceAttachmentName, $attachmentMatch);
        }

        if (null !== $ignored = $this->ignoredSenderMatch($header)) {
            $signals[] = new BillingSignal('ignored_sender', $this->weights->ignoredSender, $ignored);
        }

        if ('' !== $localPart && in_array($localPart, $this->weights->newsletterLocalParts, true)) {
            $signals[] = new BillingSignal('newsletter', $this->weights->newsletter, $localPart);
        }

        if ($this->isOwnThread($subject)) {
            $signals[] = new BillingSignal('own_thread', $this->weights->ownThread);
        }

        $score = 0;

        foreach ($signals as $signal) {
            $score += $signal->weight;
        }

        return new BillingScoreResult(
            score: max(0, min(100, $score)),
            reasons: $signals,
            threshold: $this->weights->threshold,
        );
    }

    /**
     * ¿Reconocemos al remitente? Se prueba primero el dominio (más estable) y
     * después la dirección exacta, porque un proveedor puede facturar desde
     * varios buzones del mismo dominio.
     */
    private function resolveProvider(string $domain, ?string $fromAddress): ?string
    {
        if ('' !== $domain) {
            $identity = $this->providerIdentities->findByTypeAndValue(ProviderIdentityType::DOMAIN, $domain);

            if (null !== $identity) {
                return $identity->getProvider()->getName();
            }
        }

        if (null !== $fromAddress && '' !== $fromAddress) {
            $identity = $this->providerIdentities->findByTypeAndValue(ProviderIdentityType::SENDER, mb_strtolower($fromAddress));

            if (null !== $identity) {
                return $identity->getProvider()->getName();
            }
        }

        return null;
    }

    /**
     * ¿Está el remitente en la lista de ignorados?
     *
     * La lista admite tres formas, y confundirlas es un error caro: una entrada
     * mal interpretada descarta facturas reales en silencio.
     *
     * - `facturas@proveedor.com` → dirección exacta.
     * - `@proveedor.com` → cualquier dirección de ese dominio.
     * - `proveedor.com` → el dominio del remitente.
     *
     * La comparación de dominios es **por etiquetas completas**: `linkedin.com`
     * coincide con `mail.linkedin.com` pero no con `falsolinkedin.com` ni con
     * `linkedin.com.evil.io`. Una comparación por subcadena sobre la dirección
     * completa haría que `x.com` descartara el correo de `max.com`.
     */
    private function ignoredSenderMatch(ImapMessageHeader $header): ?string
    {
        $address = null === $header->fromAddress ? '' : mb_strtolower($header->fromAddress);
        $domain = $header->senderDomain() ?? '';

        foreach ($this->weights->ignoredSenders as $entry) {
            if (str_starts_with($entry, '@')) {
                if ('' !== $domain && $this->domainMatches($domain, mb_substr($entry, 1))) {
                    return $entry;
                }

                continue;
            }

            if (str_contains($entry, '@')) {
                if ($address === $entry) {
                    return $entry;
                }

                continue;
            }

            if ('' !== $domain && $this->domainMatches($domain, $entry)) {
                return $entry;
            }
        }

        return null;
    }

    private function domainMatches(string $domain, string $candidate): bool
    {
        return $domain === $candidate || str_ends_with($domain, '.'.$candidate);
    }

    private function hasPdfAttachment(ImapMessageHeader $header): bool
    {
        return null !== $this->pdfName($header);
    }

    private function pdfName(ImapMessageHeader $header): ?string
    {
        foreach ($header->attachmentNames as $index => $name) {
            $type = $header->attachmentTypes[$index] ?? '';

            if (str_contains(mb_strtolower($type), 'pdf') || str_ends_with(mb_strtolower($name), '.pdf')) {
                return $name;
            }
        }

        return null;
    }

    private function matchedAttachmentName(ImapMessageHeader $header): ?string
    {
        foreach ($header->attachmentNames as $name) {
            $normalized = mb_strtolower($name);

            foreach ($this->weights->invoiceAttachmentPatterns as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $keywords
     *
     * @return list<string>
     */
    private function matchedKeywords(string $haystack, array $keywords): array
    {
        $matched = [];

        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                $matched[] = $keyword;
            }
        }

        return $matched;
    }

    /**
     * Un `Re:` o `Fwd:` sobre un asunto de facturación suele ser una consulta
     * interna, no un cobro nuevo.
     */
    private function isOwnThread(string $subject): bool
    {
        $trimmed = trim($subject);

        foreach (['re:', 're :', 'fwd:', 'fw:', 'rv:', 'res:', 'enc:', 'reenvío:'] as $prefix) {
            if (str_starts_with($trimmed, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
