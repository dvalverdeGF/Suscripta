<?php

declare(strict_types=1);

namespace App\Processing\Application\Provider\Parsers;

use App\Processing\Application\Extraction\AmountParser;
use App\Processing\Application\Extraction\DateParser;
use App\Processing\Application\Extraction\InvoiceNumberReader;
use App\Processing\Domain\Provider\ProviderParserContext;
use App\Processing\Domain\Provider\ProviderParseResult;
use App\Processing\Domain\Provider\ProviderParserInterface;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use DateTimeImmutable;

use function is_string;
use function mb_strtolower;
use function preg_match;
use function round;
use function str_contains;
use function trim;

/**
 * Parser de código para Acme Cloud (ARCHITECTURE.md §13.7).
 *
 * Segundo ejemplo de la familia "parser de código", junto a {@see OvhParser}.
 * Existe por dos razones:
 *
 * 1. **El pipeline lo necesita.** `ProviderParser.key` referencia la clave de
 *    un parser de código, así que un proveedor cuyo parser es `acme` necesita
 *    una clase con esa clave; si no existiera, la fila se ignoraría en
 *    silencio y el documento volvería a la extracción genérica.
 * 2. **Es la plantilla de referencia.** `AcmeParser` es deliberadamente
 *    pequeño y legible: muestra cómo se escribe un parser real, con su guarda
 *    de formato, sus expresiones regulares y su confianza ponderada.
 *
 * La plantilla de Acme Cloud es estable y en español: rótulos fijos (`Factura`,
 * `Fecha`, `Total`), número de factura con prefijo de proveedor (`ACME-`) y
 * periodicidad declarada en el propio documento. Un cambio de plantilla no
 * rompe el lote: el parser devuelve `null`, el contador de fallos sube y el
 * pipeline sigue con el extractor determinista.
 */
final readonly class AcmeParser implements ProviderParserInterface
{
    public const string KEY = 'acme';

    private const string AMOUNT_PATTERN = '/(?:total\s*(?:a\s*pagar|con\s*iva|iva\s*incluido|ttc)?|importe\s*total|total\s*factura)\s*:?\s*([0-9][0-9\s.,]*\s*(?:€|EUR|euros?))/iu';

    /**
     * Etiquetas del número de factura de Acme.
     *
     * No se incluye la etiqueta desnuda «factura» a propósito: el lector
     * compartido ya la cubre, y aquí solo se listan las formas propias de la
     * plantilla de Acme, que son más específicas y por tanto más fiables.
     */
    private const string INVOICE_NUMBER_LABEL = '(?:n[°ºo]\s*de\s*factura|num[eé]ro\s*de\s*factura|factura\s*n[°ºo]|invoice\s*(?:number|no\.?))';

    private const string INVOICE_DATE_PATTERN = '/(?:fecha\s*(?:de\s*)?(?:factura|emisi[oó]n|facturaci[oó]n)|date\s*(?:de\s*)?facture|issue\s*date)\s*:?\s*([0-9]{1,2}[\/.\-][0-9]{1,2}[\/.\-][0-9]{2,4})/iu';

    private const string DUE_DATE_PATTERN = '/(?:fecha\s*de\s*vencimiento|vencimiento|due\s*date)\s*:?\s*([0-9]{1,2}[\/.\-][0-9]{1,2}[\/.\-][0-9]{2,4})/iu';

    private const string PLAN_PATTERN = '/(?:plan|suscripci[oó]n|abono|producto)\s*:?\s*([A-Za-z0-9][A-Za-z0-9 .\-_]{2,59})/iu';

    /** @var list<string> */
    private const array FORMAT_MARKERS = ['acme', 'acme.example', 'acme cloud'];

    public function __construct(
        private AmountParser $amountParser,
        private DateParser $dateParser,
        private InvoiceNumberReader $invoiceNumbers,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function parse(ProviderParserContext $context): ?ProviderParseResult
    {
        $haystack = $context->haystack();
        $lower = mb_strtolower($haystack);

        if (!$this->looksLikeAcme($lower)) {
            return null;
        }

        $signals = ['format' => 'acme'];
        $amountText = $this->capture(self::AMOUNT_PATTERN, $haystack);
        $amount = null === $amountText ? null : $this->amountParser->findTotal($amountText, Currency::EUR);

        if (null !== $amountText) {
            $signals['amount'] = $amountText;
        }

        $invoiceNumber = $this->invoiceNumbers->findWithLabel(self::INVOICE_NUMBER_LABEL, $haystack);

        if (null !== $invoiceNumber) {
            $signals['invoiceNumber'] = $invoiceNumber;
        }

        $invoiceDate = $this->date(self::INVOICE_DATE_PATTERN, $haystack);
        $dueDate = $this->date(self::DUE_DATE_PATTERN, $haystack);
        $plan = $this->capture(self::PLAN_PATTERN, $haystack);

        if (null !== $plan) {
            $signals['plan'] = $plan;
        }

        // La periodicidad por defecto no es un dato leído: si el documento no
        // aporta nada más, este parser no debe reclamarlo. Sin esta guarda,
        // cualquier correo de Acme —un boletín, un aviso— pasaría por
        // «factura reconocida» con confianza cero.
        if (null === $amount && null === $invoiceNumber && null === $invoiceDate && null === $plan) {
            return null;
        }

        $result = new ProviderParseResult(
            parserKey: self::KEY,
            confidence: $this->confidence($amount?->amountMinor, $invoiceNumber, $invoiceDate),
            amountMinor: $amount?->amountMinor,
            currency: ($amount->currency ?? Currency::EUR)->value,
            invoiceNumber: $invoiceNumber,
            invoiceDate: $invoiceDate,
            dueDate: $dueDate,
            billingPeriod: $this->period($lower),
            plan: $plan,
            serviceName: 'Acme Cloud',
            signals: $signals,
        );

        return $result->hasData() ? $result : null;
    }

    private function looksLikeAcme(string $lower): bool
    {
        foreach (self::FORMAT_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function period(string $lower): BillingPeriod
    {
        return match (true) {
            str_contains($lower, 'anual'), str_contains($lower, 'yearly') => BillingPeriod::ANNUAL,
            str_contains($lower, 'trimestral') => BillingPeriod::QUARTERLY,
            default => BillingPeriod::MONTHLY,
        };
    }

    private function capture(string $pattern, string $haystack): ?string
    {
        if (1 !== preg_match($pattern, $haystack, $matches)) {
            return null;
        }

        $value = $matches[1] ?? null;

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private function date(string $pattern, string $haystack): ?DateTimeImmutable
    {
        $captured = $this->capture($pattern, $haystack);

        return null === $captured ? null : $this->dateParser->findFirst($captured);
    }

    private function confidence(?int $amountMinor, ?string $invoiceNumber, ?DateTimeImmutable $invoiceDate): float
    {
        $score = 0.0;

        if (null !== $amountMinor) {
            $score += 0.45;
        }

        if (null !== $invoiceNumber) {
            $score += 0.30;
        }

        if (null !== $invoiceDate) {
            $score += 0.25;
        }

        return round($score, 2);
    }
}
