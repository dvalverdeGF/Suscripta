<?php

declare(strict_types=1);

namespace App\Ai\Application;

use App\Ai\Domain\ValueObject\AiExtractionResult;
use DateTimeImmutable;

use function in_array;

/**
 * Reglas de negocio sobre la salida de la IA (nivel 7).
 *
 * ## Qué no puede validar el Validator
 *
 * `symfony/validator` valida **un campo contra una regla**: «el importe es
 * positivo», «la fecha tiene este formato». Las reglas de negocio de
 * Suscripta son **relaciones entre campos**:
 *
 * - una renovación no puede ser anterior a la factura,
 * - una factura de una suscripción anual no puede tener un periodo «semanal»,
 * - un importe de 3 000 € en una suscripción de 12,99 € es una señal de que
 *   el modelo leyó mal los céntimos.
 *
 * Esas reglas van aquí. Se ejecutan **después** de que el Validator acepte el
 * DTO, y también fallan con un mensaje: el rechazo no es una excepción, es un
 * resultado que se puede guardar y mostrar.
 *
 * ## Por qué el importe absurdo no es un `Assert\LessThanOrEqual`
 *
 * Un límite fijo (100 000 €) atrapa el error de céntimos más común, pero no
 * el de «el modelo leyó 12,99 como 12,99 millones». Por eso aquí se compara
 * además con lo que ya se sabía de forma determinista: si el código ya había
 * leído 12,99 € y la IA dice 1 299 €, algo se ha leído mal.
 */
final readonly class ExtractionValidator
{
    /**
     * Importes por encima de esto se consideran absurdos en una suscripción.
     *
     * 100 000 € al año es un techo generoso: pasa por encima cualquier
     * suscripción real de una empresa pequeña, y por debajo queda el error de
     * céntimos (12,99 → 1 299).
     */
    private const int MAX_PLAUSIBLE_AMOUNT_MINOR = 100_000_00;

    /**
     * Periodos que no tienen sentido para una suscripción recurrente.
     *
     * @var list<string>
     */
    private const array IMPLAUSIBLE_PERIODS = ['one_time', 'unknown', 'custom'];

    /**
     * Valida el resultado de la IA.
     *
     * @return list<string> errores; vacío significa que el resultado es válido
     */
    public function validate(AiExtractionResult $result): array
    {
        $errors = [];

        $errors = [...$errors, ...$this->validateAmount($result)];
        $errors = [...$errors, ...$this->validateDates($result)];
        $errors = [...$errors, ...$this->validatePeriod($result)];

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateAmount(AiExtractionResult $result): array
    {
        if (null === $result->amountMinor) {
            return [];
        }

        if ($result->amountMinor <= 0) {
            return ['El importe no puede ser cero ni negativo.'];
        }

        if ($result->amountMinor > self::MAX_PLAUSIBLE_AMOUNT_MINOR) {
            return ['El importe parece absurdo: revisa que no esté en céntimos de más.'];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function validateDates(AiExtractionResult $result): array
    {
        $errors = [];

        $invoiceDate = $result->invoiceDateObject();
        $renewalDate = $result->renewalDateObject();

        if (null !== $invoiceDate && null !== $renewalDate && $renewalDate < $invoiceDate) {
            $errors[] = 'La fecha de renovación no puede ser anterior a la fecha de factura.';
        }

        // Una factura muy futura indica que el modelo ha confundido una fecha
        // de vencimiento con una de emisión, o ha leido un año equivocado.
        $now = new DateTimeImmutable();

        if (null !== $invoiceDate && $invoiceDate > $now->modify('+1 day')) {
            $errors[] = 'La fecha de factura es futura.';
        }

        if (null !== $renewalDate && $renewalDate > $now->modify('+6 years')) {
            $errors[] = 'La fecha de renovación es demasiado lejana.';
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validatePeriod(AiExtractionResult $result): array
    {
        $label = $result->billingPeriod;

        if (null === $label) {
            return [];
        }

        if (in_array(mb_strtolower($label), self::IMPLAUSIBLE_PERIODS, true)) {
            return ['Una suscripción no puede tener una periodicidad «'.$label.'».'];
        }

        if (null === $result->billingPeriodEnum()) {
            return ['La periodicidad «'.$label.'» no se reconoce.'];
        }

        return [];
    }
}
