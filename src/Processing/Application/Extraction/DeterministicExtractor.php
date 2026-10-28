<?php

declare(strict_types=1);

namespace App\Processing\Application\Extraction;

use App\Catalog\Domain\Enum\ProviderIdentityType;
use App\Catalog\Domain\Repository\ProviderIdentityRepositoryInterface;
use App\Documents\Domain\Enum\DocumentType;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Mailbox\Domain\Enum\MessageClassification;
use App\Processing\Domain\Dto\ExtractedDocument;
use App\Processing\Domain\Dto\MailboxKnowledge;
use App\Processing\Domain\Provider\ProviderMatch;
use App\Shared\Application\Clock;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;

use function array_map;
use function count;

use DateTimeImmutable;

use function implode;
use function mb_strpos;
use function mb_strtolower;
use function mb_substr;
use function preg_match;
use function round;
use function str_contains;
use function trim;

/**
 * Nivel 3 del pipeline: extracción determinista (ARCHITECTURE.md §13.6).
 *
 * **Regla:** no se usa IA para extraer algo que se puede obtener de forma
 * fiable por código. Este extractor resuelve la mayoría de facturas de
 * proveedores de software, que son plantillas repetidas con los mismos campos.
 *
 * Todo lo que no se puede deducir con seguridad se deja a `null`. La confianza
 * resultante es la proporción de campos clave rellenados, y es lo que decide si
 * el mensaje pasa a `CLASSIFIED` o a `REQUIRES_REVIEW`.
 */
final readonly class DeterministicExtractor
{
    public const string NAME = 'deterministic';

    private const string PERIOD_PATTERN = '/(?:every|cada|per|por)\s+(\d{1,2})\s*(month|mes|year|año|ano|week|semana|quarter|trimestre)s?|(\d{1,2})\s*(month|mes|year|año|ano|week|semana|quarter|trimestre)s?\b/iu';

    /** @var list<string> */
    private const array RENEWAL_MARKERS = [
        'renew', 'renewal', 'renovación', 'renovacion', 'renueva', 'expira', 'expires',
        'next charge', 'próximo cobro', 'proximo cobro', 'will be charged', 'se renovará',
        'auto-renew', 'vencimiento', 'due date', 'fecha de vencimiento', 'next payment',
    ];

    /** @var list<string> */
    private const array INVOICE_MARKERS = ['factura', 'invoice', 'fra ', 'inv-', 'facturación', 'facturacion'];

    /** @var list<string> */
    private const array RECEIPT_MARKERS = ['recibo', 'receipt', 'pago recibido', 'payment received', 'confirmación de pago', 'payment confirmation'];

    /** @var list<string> */
    private const array RENEWAL_NOTICE_MARKERS = ['renovación', 'renovacion', 'renewal', 'se renovará', 'will renew', 'expira', 'expires soon'];

    /** @var list<string> */
    private const array PRICE_CHANGE_MARKERS = ['nuevo precio', 'new price', 'price change', 'cambio de precio', 'actualización de precio', 'price update', 'subida de precio'];

    /** @var list<string> */
    private const array PLAN_CHANGE_MARKERS = ['cambio de plan', 'plan change', 'upgrade', 'downgrade', 'cambio de tarifa'];

    public function __construct(
        private AmountParser $amountParser,
        private DateParser $dateParser,
        private InvoiceNumberReader $invoiceNumbers,
        private ProviderIdentityRepositoryInterface $providerIdentities,
        private Clock $clock,
    ) {
    }

    /**
     * @param ProviderMatch|null    $provider  resolución ya hecha por el nivel 4, si la hay
     * @param MailboxKnowledge|null $knowledge lo aprendido de este buzón (Fase 13)
     */
    public function extract(
        ImapMessageHeader $header,
        string $bodyText = '',
        ?ProviderMatch $provider = null,
        ?MailboxKnowledge $knowledge = null,
    ): ExtractedDocument {
        $haystack = trim($header->subject."\n".$bodyText);
        $signals = [];

        $amount = $this->amountParser->findTotal($haystack);

        if (null !== $amount) {
            $signals['amount'] = $amount->format();
        }

        $invoiceNumber = $this->extractInvoiceNumber($bodyText, $header->subject);

        if (null !== $invoiceNumber) {
            $signals['invoiceNumber'] = $invoiceNumber;
        }

        $notBefore = $this->clock->now()->modify('-5 years');
        $dates = $this->dateParser->findAll($haystack, $notBefore);

        if ([] !== $dates) {
            $signals['dates'] = implode(', ', array_map(
                static fn (DateTimeImmutable $date): string => $date->format('Y-m-d'),
                $dates,
            ));
        }

        $billingPeriod = $this->detectBillingPeriod($haystack);

        if (null !== $billingPeriod) {
            $signals['billingPeriod'] = $billingPeriod->value;
        }

        // La periodicidad aprendida del buzón es el dato que más IA ahorra:
        // sin ella el documento no es accionable y hay que escalar a la IA
        // (D-52). El texto del correo manda; lo aprendido solo rellena el hueco.
        if (null === $billingPeriod && null !== $knowledge?->billingPeriod) {
            $learned = $knowledge->billingPeriod;

            if ($learned->isRecurring()) {
                $billingPeriod = $learned;
                $signals['billingPeriod'] = $billingPeriod->value;
                $signals['billingPeriodSource'] = 'mailbox_knowledge';
            }
        }

        $classification = $this->classify($haystack);
        $knownProvider = null === $provider
            ? $this->resolveKnownProviderName($header)
            : ($provider->isKnown() ? $provider->displayName() : null);
        $providerName = $knownProvider ?? $provider?->displayName() ?? $this->provisionalProviderName($header);
        $renewalDate = $this->detectRenewalDate($haystack, $dates);

        if (null !== $renewalDate) {
            $signals['renewalDate'] = $renewalDate->format('Y-m-d');
        }

        $signals['providerKnown'] = null !== $knownProvider ? 'yes' : 'no';

        $documentType = DocumentType::fromClassification($classification);

        // El tipo de documento aprendido es el último recurso: solo se usa si
        // el texto no ha permitido clasificar el correo.
        if (MessageClassification::UNKNOWN === $classification && null !== $knowledge?->documentType) {
            $documentType = $knowledge->documentType;
            $signals['documentTypeSource'] = 'mailbox_knowledge';
        }

        $document = new ExtractedDocument(
            tier: ExtractionTier::DETERMINISTIC,
            confidence: $this->confidence($amount, $billingPeriod, $knownProvider, MessageClassification::UNKNOWN !== $classification || $knowledge?->documentType !== null),
            amountMinor: $amount?->amountMinor,
            currency: ($amount->currency ?? Currency::EUR)->value,
            invoiceNumber: $invoiceNumber,
            invoiceDate: $dates[0] ?? null,
            dueDate: $this->detectDueDate($haystack, $dates),
            billingPeriod: $billingPeriod,
            sender: $header->fromAddress,
            senderDomain: $header->senderDomain(),
            subject: $header->subject,
            documentType: $documentType,
            providerName: $providerName,
            serviceName: $providerName,
            renewalDate: $renewalDate,
            rawSignals: $signals,
        );

        return $document;
    }

    /**
     * Confianza = proporción de campos clave rellenados.
     *
     * Es deliberadamente conservadora: sin importe y sin periodicidad no hay
     * coste recurrente que calcular, así que la confianza se queda por debajo
     * del umbral y el mensaje va a revisión en lugar de generar una propuesta
     * inventada.
     */
    private function confidence(
        ?Money $amount,
        ?BillingPeriod $period,
        ?string $provider,
        bool $classified,
    ): float {
        $score = 0.0;

        if (null !== $amount) {
            $score += 0.35;
        }

        if (null !== $period && $period->isRecurring()) {
            $score += 0.30;
        }

        if (null !== $provider) {
            $score += 0.20;
        }

        if ($classified) {
            $score += 0.15;
        }

        return round($score, 2);
    }

    /**
     * Número de factura.
     *
     * Se busca **primero en el cuerpo** y solo después en el asunto. El cuerpo
     * es donde está el dato; el asunto es una etiqueta que el remitente escribe
     * para el humano («Factura 2026-10 de OVHcloud») y no para la máquina.
     * Darle prioridad al cuerpo evita que un periodo del asunto se cuele como
     * número de factura.
     */
    private function extractInvoiceNumber(string $bodyText, string $subject): ?string
    {
        foreach ([$bodyText, $subject] as $source) {
            $candidate = $this->firstInvoiceNumberIn($source);

            if (null !== $candidate) {
                return $candidate;
            }
        }

        return null;
    }

    private function firstInvoiceNumberIn(string $haystack): ?string
    {
        return $this->invoiceNumbers->find($haystack);
    }

    private function detectBillingPeriod(string $haystack): ?BillingPeriod
    {
        if (1 === preg_match(self::PERIOD_PATTERN, $haystack, $matches)) {
            $count = (int) ($matches[1] ?: ($matches[3] ?? 0));
            $unit = mb_strtolower($matches[2] ?: ($matches[4] ?? ''));

            if ($count > 0 && '' !== $unit) {
                $months = match (true) {
                    str_contains($unit, 'week'), str_contains($unit, 'semana') => null,
                    str_contains($unit, 'quarter'), str_contains($unit, 'trimestre') => 3,
                    str_contains($unit, 'year'), str_contains($unit, 'año'), str_contains($unit, 'ano') => 12,
                    default => 1,
                };

                if (null === $months) {
                    return BillingPeriod::WEEKLY;
                }

                $total = $months * $count;

                return match ($total) {
                    1 => BillingPeriod::MONTHLY,
                    2 => BillingPeriod::BIMONTHLY,
                    3 => BillingPeriod::QUARTERLY,
                    6 => BillingPeriod::SEMIANNUAL,
                    12 => BillingPeriod::ANNUAL,
                    24 => BillingPeriod::BIENNIAL,
                    36 => BillingPeriod::TRIENNIAL,
                    default => BillingPeriod::CUSTOM,
                };
            }
        }

        // Etiquetas sueltas: "mensual", "anual", "yearly", "trimestral"…
        $lower = mb_strtolower($haystack);

        foreach (['mensual', 'monthly', 'anual', 'annual', 'yearly', 'trimestral', 'quarterly', 'semestral', 'semiannual', 'bimestral', 'bimonthly', 'semanal', 'weekly'] as $label) {
            if (str_contains($lower, $label)) {
                return BillingPeriod::fromLabel($label);
            }
        }

        return null;
    }

    private function classify(string $haystack): MessageClassification
    {
        // Los marcadores están en minúsculas y los asuntos llegan capitalizados
        // ("Factura OVH"), así que la comparación tiene que ser insensible.
        $haystack = mb_strtolower($haystack);

        // El orden decide qué gana cuando un mensaje encaja en varios sitios, y
        // las facturas reales encajan en varios: casi todas avisan de que se
        // renovarán. Por eso `INVOICE` va **antes** que `RENEWAL_NOTICE`.
        //
        // Tipar de factura un mensaje que menciona la renovación no pierde la
        // señal de cobro futuro: `renewalDate` se extrae del cuerpo por su
        // cuenta, no de la clasificación. Lo que sí se pierde al revés es el
        // hecho: un documento tipado `OTHER` no cuenta como factura ni entra en
        // el historial de precios, y la renovación queda registrada de un
        // documento que el producto no considera un cobro.
        return match (true) {
            $this->containsAny($haystack, self::PRICE_CHANGE_MARKERS) => MessageClassification::PRICE_CHANGE,
            $this->containsAny($haystack, self::PLAN_CHANGE_MARKERS) => MessageClassification::PLAN_CHANGE,
            $this->containsAny($haystack, self::INVOICE_MARKERS) => MessageClassification::INVOICE,
            $this->containsAny($haystack, self::RECEIPT_MARKERS) => MessageClassification::RECEIPT,
            $this->containsAny($haystack, self::RENEWAL_NOTICE_MARKERS) => MessageClassification::RENEWAL_NOTICE,
            default => MessageClassification::UNKNOWN,
        };
    }

    private function resolveKnownProviderName(ImapMessageHeader $header): ?string
    {
        $domain = $header->senderDomain();

        if (null !== $domain) {
            $identity = $this->providerIdentities->findByTypeAndValue(ProviderIdentityType::DOMAIN, $domain);

            if (null !== $identity) {
                return $identity->getProvider()->getName();
            }
        }

        if (null !== $header->fromAddress) {
            $identity = $this->providerIdentities->findByTypeAndValue(ProviderIdentityType::SENDER, mb_strtolower($header->fromAddress));

            if (null !== $identity) {
                return $identity->getProvider()->getName();
            }
        }

        return null;
    }

    /**
     * Nombre provisional para un proveedor que todavía no conocemos.
     *
     * Sin esto el producto no podría descubrir **nada nuevo**: la primera
     * factura de cualquier proveedor llega, por definición, de un remitente
     * desconocido, y exigir un nombre verificado dejaría el mensaje en
     * `REQUIRES_REVIEW` para siempre. Es exactamente lo que haría una persona:
     * quedarse con el nombre visible del remitente y, si no lo hay, con su
     * dominio.
     *
     * El nombre provisional **no** cuenta como proveedor identificado a
     * efectos de confianza (`rawSignals.providerKnown`), así que no infla la
     * puntuación ni el emparejamiento.
     */
    private function provisionalProviderName(ImapMessageHeader $header): ?string
    {
        $fromName = null === $header->fromName ? null : trim($header->fromName);

        if (null !== $fromName && '' !== $fromName) {
            return mb_substr($fromName, 0, 120);
        }

        $domain = $header->senderDomain();

        return null === $domain || '' === $domain ? null : mb_substr($domain, 0, 120);
    }

    /**
     * Fecha de renovación: la primera fecha que aparece cerca de una marca de
     * renovación. Si no hay marca, no se inventa.
     *
     * @param list<DateTimeImmutable> $dates
     */
    private function detectRenewalDate(string $haystack, array $dates): ?DateTimeImmutable
    {
        if ([] === $dates) {
            return null;
        }

        $lower = mb_strtolower($haystack);

        foreach (self::RENEWAL_MARKERS as $marker) {
            $position = mb_strpos($lower, $marker);

            if (false === $position) {
                continue;
            }

            $window = mb_substr($lower, $position, 200);

            foreach ($dates as $date) {
                if (str_contains($window, $date->format('Y-m-d'))
                    || str_contains($window, $date->format('d/m/Y'))
                    || str_contains($window, $date->format('d.m.Y'))) {
                    return $date;
                }
            }
        }

        return null;
    }

    /**
     * @param list<DateTimeImmutable> $dates
     */
    private function detectDueDate(string $haystack, array $dates): ?DateTimeImmutable
    {
        if (count($dates) < 2) {
            return null;
        }

        $lower = mb_strtolower($haystack);

        foreach (['vencimiento', 'due date', 'fecha límite', 'fecha limite', 'pagar antes', 'pay by', 'payment due'] as $marker) {
            $position = mb_strpos($lower, $marker);

            if (false === $position) {
                continue;
            }

            $window = mb_substr($lower, $position, 200);

            foreach ($dates as $date) {
                if (str_contains($window, $date->format('Y-m-d'))
                    || str_contains($window, $date->format('d/m/Y'))
                    || str_contains($window, $date->format('d.m.Y'))) {
                    return $date;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Expuesto para los tests y para el log de extracción.
     */
    public function name(): string
    {
        return self::NAME;
    }
}
