<?php

declare(strict_types=1);

namespace App\Processing\Application\Billing;

use function is_array;
use function is_string;

/**
 * Pesos del `billingScore` (ARCHITECTURE.md §13.4).
 *
 * Viven en configuración versionada (`config/packages/processing.yaml`) y no
 * enterrados en el código: ajustar el filtro es una decisión de producto, no un
 * cambio de programa. Los valores por defecto están documentados en la tabla de
 * §13.4 y cualquier cambio debe actualizar esa tabla.
 */
final readonly class BillingScoreWeights
{
    /**
     * @param list<string> $subjectKeywords
     * @param list<string> $renewalKeywords
     * @param list<string> $billingLocalParts
     * @param list<string> $invoiceAttachmentPatterns
     * @param list<string> $ignoredSenders
     * @param list<string> $newsletterLocalParts
     */
    public function __construct(
        public int $threshold = 40,
        public int $subjectKeyword = 25,
        public int $knownProvider = 20,
        public int $pdfAttachment = 15,
        public int $detectableAmount = 15,
        public int $renewalLanguage = 10,
        public int $billingLocalPart = 10,
        public int $invoiceAttachmentName = 10,
        public int $ignoredSender = -100,
        public int $newsletter = -30,
        public int $ownThread = -10,
        public array $subjectKeywords = [],
        public array $renewalKeywords = [],
        public array $billingLocalParts = [],
        public array $invoiceAttachmentPatterns = [],
        public array $ignoredSenders = [],
        public array $newsletterLocalParts = [],
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $int = static fn (string $key, int $default): int => is_numeric($config[$key] ?? null)
            ? (int) $config[$key]
            : $default;

        return new self(
            threshold: $int('threshold', 40),
            subjectKeyword: $int('subject_keyword', 25),
            knownProvider: $int('known_provider', 20),
            pdfAttachment: $int('pdf_attachment', 15),
            detectableAmount: $int('detectable_amount', 15),
            renewalLanguage: $int('renewal_language', 10),
            billingLocalPart: $int('billing_local_part', 10),
            invoiceAttachmentName: $int('invoice_attachment_name', 10),
            ignoredSender: $int('ignored_sender', -100),
            newsletter: $int('newsletter', -30),
            ownThread: $int('own_thread', -10),
            subjectKeywords: self::stringList($config, 'subject_keywords', self::defaultSubjectKeywords()),
            renewalKeywords: self::stringList($config, 'renewal_keywords', self::defaultRenewalKeywords()),
            billingLocalParts: self::stringList($config, 'billing_local_parts', self::defaultBillingLocalParts()),
            invoiceAttachmentPatterns: self::stringList($config, 'invoice_attachment_patterns', self::defaultInvoiceAttachmentPatterns()),
            ignoredSenders: self::stringList($config, 'ignored_senders', []),
            newsletterLocalParts: self::stringList($config, 'newsletter_local_parts', self::defaultNewsletterLocalParts()),
        );
    }

    /**
     * Lista de cadenas normalizadas (minúsculas, sin espacios sobrantes) leída
     * de la configuración. Si la clave no existe o no aporta ningún valor
     * utilizable se devuelve la lista por defecto, de modo que una
     * configuración incompleta nunca deja el clasificador sin señales.
     *
     * @param array<string, mixed> $config
     * @param list<string>         $default
     *
     * @return list<string>
     */
    private static function stringList(array $config, string $key, array $default): array
    {
        $value = $config[$key] ?? null;

        if (!is_array($value)) {
            return $default;
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item) && '' !== trim($item)) {
                $items[] = mb_strtolower(trim($item));
            }
        }

        return [] === $items ? $default : $items;
    }

    /** @return list<string> */
    public static function defaultSubjectKeywords(): array
    {
        return [
            'invoice', 'factura', 'receipt', 'recibo', 'payment', 'pago',
            'subscription', 'suscripción', 'suscripcion', 'renewal', 'renovación', 'renovacion',
            'billing', 'statement', 'charge', 'cargo', 'abono', 'cuota', 'mensualidad',
        ];
    }

    /** @return list<string> */
    public static function defaultRenewalKeywords(): array
    {
        return [
            'renew', 'renewal', 'renovación', 'renovacion', 'renueva', 'expira', 'expires',
            'next charge', 'próximo cobro', 'proximo cobro', 'will be charged', 'se renovará',
            'auto-renew', 'autorenovación', 'vencimiento', 'due date', 'fecha de vencimiento',
        ];
    }

    /** @return list<string> */
    public static function defaultBillingLocalParts(): array
    {
        return [
            'invoice', 'invoices', 'factura', 'facturas', 'facturacion', 'facturación',
            'billing', 'billing-noreply', 'payments', 'pagos', 'receipts', 'recibos',
            'no-reply-invoice', 'noreply-invoice', 'cuentas', 'accounting',
        ];
    }

    /** @return list<string> */
    public static function defaultInvoiceAttachmentPatterns(): array
    {
        return [
            'factura', 'invoice', 'recibo', 'receipt', 'fra_', 'inv-', 'fac-', 'rec-',
            'billing', 'cuota', 'abono',
        ];
    }

    /** @return list<string> */
    public static function defaultNewsletterLocalParts(): array
    {
        return [
            'newsletter', 'news', 'marketing', 'info', 'hello', 'hola', 'comunicacion',
            'comunicación', 'notificaciones', 'notifications', 'social', 'community',
            'noreply', 'no-reply', 'donotreply', 'do-not-reply', 'mailer', 'bounce',
        ];
    }
}
