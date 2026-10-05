<?php

declare(strict_types=1);

namespace App\Processing\Application\Provider\Parsers;

use App\Processing\Application\Extraction\AmountParser;
use App\Processing\Application\Extraction\DateParser;
use App\Processing\Domain\Provider\ProviderParseResult;
use App\Processing\Domain\Provider\ProviderParserContext;
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
 * Parser de código para OVHcloud (ARCHITECTURE.md §13.7).
 *
 * Es el ejemplo de la familia "parser de código": conoce la plantilla exacta de
 * un proveedor y por eso es más fiable que cualquier heurística. La primera
 * factura de OVH puede necesitar IA; a partir de la segunda, no.
 *
 * Las expresiones regulares están escritas para aguantar las dos lenguas en las
 * que OVH factura (español y francés) y las variantes de etiqueta que usan
 * (`Total TTC`, `Importe total`, `Montant total`). Un cambio de plantilla no
 * rompe el lote: el parser devuelve `null`, el contador de fallos sube y el
 * pipeline sigue con el extractor genérico.
 */
final readonly class OvhParser implements ProviderParserInterface
{
    public const string KEY = 'ovh';

    private const string AMOUNT_PATTERN = '/(?:total\s*(?:ttc|t\.t\.c\.?)?|importe\s*total|montant\s*total|total\s*de\s*la\s*factura)\s*:?\s*([0-9][0-9\s.,]*\s*(?:€|EUR|euros?))/iu';

    private const string INVOICE_NUMBER_PATTERN = '/(?:n[°ºo]\s*de\s*facture|num[eé]ro\s*de\s*facture|facture\s*n[°ºo]|factura\s*n[°ºo]|invoice\s*(?:number|no\.?))\s*:?\s*([A-Z0-9][A-Z0-9\-\/]{3,29})/iu';

    private const string INVOICE_DATE_PATTERN = '/(?:date\s*(?:de\s*)?(?:facture|facturaci[oó]n|invoice)|fecha\s*(?:de\s*)?(?:factura|facturaci[oó]n))\s*:?\s*([0-9]{1,2}[\/.\-][0-9]{1,2}[\/.\-][0-9]{2,4})/iu';

    private const string DUE_DATE_PATTERN = '/(?:date\s*d[’\']?[eé]ch[eé]ance|[eé]ch[eé]ance|fecha\s*de\s*vencimiento|vencimiento)\s*:?\s*([0-9]{1,2}[\/.\-][0-9]{1,2}[\/.\-][0-9]{2,4})/iu';

    private const string PLAN_PATTERN = '/(?:abonnement|suscripci[oó]n|plan|offre|oferta)\s*:?\s*([A-Za-z0-9][A-Za-z0-9 .\-_]{2,59})/iu';

    /** @var list<string> */
    private const array FORMAT_MARKERS = ['ovh', 'ovhcloud', 'ovh.com', 'ovh.es'];

    public function __construct(
        private AmountParser $amountParser,
        private DateParser $dateParser,
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

        if (!$this->looksLikeOvh($lower)) {
            return null;
        }

        $signals = ['format' => 'ovh'];
        $amountText = $this->capture(self::AMOUNT_PATTERN, $haystack);
        $amount = null === $amountText ? null : $this->amountParser->findTotal($amountText, Currency::EUR);

        if (null !== $amountText) {
            $signals['amount'] = $amountText;
        }

        $invoiceNumber = $this->capture(self::INVOICE_NUMBER_PATTERN, $haystack);

        if (null !== $invoiceNumber) {
            $signals['invoiceNumber'] = $invoiceNumber;
        }

        $invoiceDate = $this->date(self::INVOICE_DATE_PATTERN, $haystack);
        $dueDate = $this->date(self::DUE_DATE_PATTERN, $haystack);
        $plan = $this->capture(self::PLAN_PATTERN, $haystack);

        if (null !== $plan) {
            $signals['plan'] = $plan;
        }

        // OVH factura mensualmente por defecto, y lo dice en el propio
        // documento. Si no lo dice, se asume mensual porque es su modalidad
        // habitual y el usuario puede corregirlo en un clic.
        $period = $this->period($lower);

        // La periodicidad por defecto no es un dato leído: si el documento no
        // aporta nada más, este parser no debe reclamarlo. Sin esta guarda,
        // cualquier correo de OVH —un boletín, un aviso de mantenimiento—
        // pasaría por «factura reconocida» con confianza cero.
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
            billingPeriod: $period,
            plan: $plan,
            serviceName: 'OVHcloud',
            signals: $signals,
        );

        return $result->hasData() ? $result : null;
    }

    /**
     * @param list<string> $markers
     */
    private function looksLikeOvh(string $lower): bool
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
            str_contains($lower, 'anual'), str_contains($lower, 'annuel'), str_contains($lower, 'yearly') => BillingPeriod::ANNUAL,
            str_contains($lower, 'trimestral'), str_contains($lower, 'trimestriel') => BillingPeriod::QUARTERLY,
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
