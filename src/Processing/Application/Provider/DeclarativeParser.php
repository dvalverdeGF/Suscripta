<?php

declare(strict_types=1);

namespace App\Processing\Application\Provider;

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
 * Parser dirigido por configuración (ARCHITECTURE.md §13.7).
 *
 * Es la pieza que evita tener que escribir cientos de parsers a mano. Cuando el
 * pipeline aprende la plantilla de un proveedor —o cuando alguien la escribe a
 * mano en `ProviderParser.config`— este parser la aplica sin desplegar código.
 *
 * Claves admitidas en `config`:
 *
 * | Clave | Significado |
 * |---|---|
 * | `required_any` | lista de fragmentos; si no aparece ninguno, el parser no reconoce el documento |
 * | `amount_pattern` | expresión regular con el importe en el grupo 1 |
 * | `currency` | moneda por defecto del proveedor (`EUR`, `USD`…) |
 * | `invoice_number_pattern` | expresión regular con el número en el grupo 1 |
 * | `invoice_date_pattern` | expresión regular con la fecha en el grupo 1 |
 * | `due_date_pattern` | expresión regular con el vencimiento en el grupo 1 |
 * | `renewal_date_pattern` | expresión regular con la renovación en el grupo 1 |
 * | `period` | periodicidad fija (`monthly`, `annual`…) |
 * | `plan_pattern` | expresión regular con el plan en el grupo 1 |
 * | `service_name` | nombre de servicio fijo |
 *
 * `required_any` es lo que impide que un parser declarativo se aplique a
 * cualquier correo: sin una comprobación de formato, una expresión regular
 * demasiado laxa acabaría leyendo importes de un boletín.
 */
final readonly class DeclarativeParser implements ProviderParserInterface
{
    public const string KEY = 'declarative';

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

        if (!$this->matchesFormat($context, $haystack)) {
            return null;
        }

        $signals = [];
        $currency = $this->currency($context);

        $amountText = $this->capture($context, 'amount_pattern', $haystack);

        if (null !== $amountText) {
            $signals['amount_pattern'] = $amountText;
        }

        $amount = null === $amountText
            ? null
            : $this->amountParser->findTotal($amountText, $currency);

        $invoiceNumber = $this->capture($context, 'invoice_number_pattern', $haystack);

        if (null !== $invoiceNumber) {
            $signals['invoice_number_pattern'] = $invoiceNumber;
        }

        $notBefore = null;
        $invoiceDate = $this->date($context, 'invoice_date_pattern', $haystack, $notBefore);
        $dueDate = $this->date($context, 'due_date_pattern', $haystack, $notBefore);
        $renewalDate = $this->date($context, 'renewal_date_pattern', $haystack, $notBefore);
        $plan = $this->capture($context, 'plan_pattern', $haystack);

        if (null !== $plan) {
            $signals['plan_pattern'] = $plan;
        }

        $period = $this->period($context);

        if (null !== $period) {
            $signals['period'] = $period->value;
        }

        $result = new ProviderParseResult(
            parserKey: self::KEY,
            confidence: $this->confidence($amount?->amountMinor, $invoiceNumber, $period, $invoiceDate),
            amountMinor: $amount?->amountMinor,
            currency: ($amount->currency ?? $currency)->value,
            invoiceNumber: $invoiceNumber,
            invoiceDate: $invoiceDate,
            dueDate: $dueDate,
            billingPeriod: $period,
            plan: $plan,
            serviceName: $context->configString('service_name'),
            renewalDate: $renewalDate,
            signals: $signals,
        );

        return $result->hasData() ? $result : null;
    }

    /**
     * Comprueba que el documento tiene la forma esperada.
     *
     * Sin `required_any` configurado se acepta cualquier documento, que es lo
     * razonable cuando el parser se ha creado a partir de una factura concreta
     * de ese proveedor: el proveedor ya se ha identificado por su dominio.
     */
    private function matchesFormat(ProviderParserContext $context, string $haystack): bool
    {
        $required = $context->configList('required_any');

        if ([] === $required) {
            return true;
        }

        $lower = mb_strtolower($haystack);

        foreach ($required as $needle) {
            if (str_contains($lower, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function capture(ProviderParserContext $context, string $key, string $haystack): ?string
    {
        $pattern = $context->configString($key);

        if (null === $pattern) {
            return null;
        }

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

    private function date(
        ProviderParserContext $context,
        string $key,
        string $haystack,
        ?DateTimeImmutable $notBefore,
    ): ?DateTimeImmutable {
        $captured = $this->capture($context, $key, $haystack);

        if (null === $captured) {
            return null;
        }

        return $this->dateParser->findFirst($captured, $notBefore);
    }

    private function period(ProviderParserContext $context): ?BillingPeriod
    {
        $label = $context->configString('period');

        return null === $label ? null : BillingPeriod::tryFromLabel($label);
    }

    private function currency(ProviderParserContext $context): Currency
    {
        $code = $context->configString('currency');

        return null === $code ? Currency::EUR : (Currency::tryFrom($code) ?? Currency::EUR);
    }

    /**
     * Un parser declarativo solo es tan fiable como su configuración: si ha
     * leído importe, número y periodicidad, la plantilla encaja.
     */
    private function confidence(?int $amountMinor, ?string $invoiceNumber, ?BillingPeriod $period, ?DateTimeImmutable $invoiceDate): float
    {
        $score = 0.0;

        if (null !== $amountMinor) {
            $score += 0.40;
        }

        if (null !== $period && $period->isRecurring()) {
            $score += 0.25;
        }

        if (null !== $invoiceNumber) {
            $score += 0.20;
        }

        if (null !== $invoiceDate) {
            $score += 0.15;
        }

        return round($score, 2);
    }
}
