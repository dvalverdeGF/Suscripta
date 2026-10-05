<?php

declare(strict_types=1);

namespace App\Processing\Application\Matching;

use function abs;

use App\Processing\Domain\Dto\ExtractedDocument;
use App\Processing\Domain\Dto\ServiceMatchResult;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;

use function mb_strtolower;
use function preg_match;
use function preg_quote;
use function sprintf;
use function str_contains;
use function strpos;
use function substr;
use function trim;

/**
 * Empareja un documento extraído con el inventario de servicios
 * (ARCHITECTURE.md §13.10, D-35).
 *
 * El emparejamiento es **ponderado y explicable**, nunca binario: un mismo
 * proveedor puede tener varios servicios (OVH hosting y OVH dominio) y una
 * coincidencia exacta de nombre no siempre significa lo mismo. Por eso se
 * acumulan señales independientes y se devuelve la mejor candidata junto con
 * el desglose que la justifica.
 *
 * Regla de producto: **nunca se crea un servicio automáticamente**. Este
 * servicio solo puntúa; la decisión la toma el usuario sobre un `Discovery`.
 */
final readonly class ServiceMatcher
{
    public const int PROVIDER_WEIGHT = 40;
    public const int DOMAIN_WEIGHT = 20;
    public const int NAME_WEIGHT = 20;
    public const int CURRENCY_WEIGHT = 5;
    public const int PERIODICITY_WEIGHT = 5;
    public const int AMOUNT_WEIGHT = 5;
    public const int SENDER_WEIGHT = 5;

    /**
     * Tolerancia de importe: una subida de precio sigue siendo el mismo
     * servicio, pero un importe muy distinto suele ser otro plan u otro
     * producto.
     */
    private const float AMOUNT_TOLERANCE = 0.10;

    public function __construct(
        private ServiceRepositoryInterface $services,
    ) {
    }

    public function match(ExtractedDocument $document): ServiceMatchResult
    {
        $candidates = $this->services->findForOrganization(ServiceFilters::none());

        if ([] === $candidates) {
            return ServiceMatchResult::none();
        }

        $best = ServiceMatchResult::none();

        foreach ($candidates as $service) {
            $result = $this->scoreAgainst($service, $document);

            if ($result->score > $best->score) {
                $best = $result;
            }
        }

        return $best;
    }

    private function scoreAgainst(Service $service, ExtractedDocument $document): ServiceMatchResult
    {
        $score = 0;
        $reasons = [];

        if (null !== $document->providerName && null !== $service->getProviderId()) {
            $score += self::PROVIDER_WEIGHT;
            $reasons[] = [
                'signal' => 'Proveedor identificado',
                'weight' => self::PROVIDER_WEIGHT,
                'detail' => $document->providerName,
            ];
        }

        if (null !== $document->senderDomain && $this->domainMatches($service, $document->senderDomain)) {
            $score += self::DOMAIN_WEIGHT;
            $reasons[] = [
                'signal' => 'Dominio del remitente',
                'weight' => self::DOMAIN_WEIGHT,
                'detail' => $document->senderDomain,
            ];
        }

        if (null !== $document->serviceName && $this->nameMatches($service->getName(), $document->serviceName)) {
            $score += self::NAME_WEIGHT;
            $reasons[] = [
                'signal' => 'Nombre del servicio',
                'weight' => self::NAME_WEIGHT,
                'detail' => $document->serviceName,
            ];
        }

        if (null !== $document->currency && $document->currency === $service->getCurrency()->value) {
            $score += self::CURRENCY_WEIGHT;
            $reasons[] = [
                'signal' => 'Misma moneda',
                'weight' => self::CURRENCY_WEIGHT,
                'detail' => $document->currency,
            ];
        }

        if (null !== $document->billingPeriod && $document->billingPeriod === $service->getBillingPeriod()) {
            $score += self::PERIODICITY_WEIGHT;
            $reasons[] = [
                'signal' => 'Misma periodicidad',
                'weight' => self::PERIODICITY_WEIGHT,
                'detail' => $document->billingPeriod->label(),
            ];
        }

        if ($this->amountMatches($service, $document)) {
            $score += self::AMOUNT_WEIGHT;
            $reasons[] = [
                'signal' => 'Importe compatible',
                'weight' => self::AMOUNT_WEIGHT,
                'detail' => null !== $document->amountMinor && null !== $document->currency
                    ? Money::of($document->amountMinor, Currency::from($document->currency))->format()
                    : null,
            ];
        }

        if (null !== $document->sender && $this->senderMatches($service, $document->sender)) {
            $score += self::SENDER_WEIGHT;
            $reasons[] = [
                'signal' => 'Remitente exacto ya visto',
                'weight' => self::SENDER_WEIGHT,
                'detail' => $document->sender,
            ];
        }

        return ServiceMatchResult::forService($service, $score, $reasons);
    }

    private function domainMatches(Service $service, string $domain): bool
    {
        $name = mb_strtolower($service->getName());
        $domain = mb_strtolower($domain);

        // `ovh.com` → `ovh`: el nombre del servicio casi nunca incluye el TLD.
        $label = $domain;
        $dot = strpos($domain, '.');

        if (false !== $dot) {
            $label = substr($domain, 0, $dot);
        }

        return '' !== $label && str_contains($name, $label);
    }

    private function nameMatches(string $serviceName, string $documentName): bool
    {
        $a = mb_strtolower(trim($serviceName));
        $b = mb_strtolower(trim($documentName));

        if ('' === $a || '' === $b) {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        return str_contains($a, $b) || str_contains($b, $a);
    }

    private function amountMatches(Service $service, ExtractedDocument $document): bool
    {
        if (null === $document->amountMinor || null === $document->currency) {
            return false;
        }

        $current = $service->getCurrentAmount();

        if (null === $current || $current->currency->value !== $document->currency) {
            return false;
        }

        $reference = $current->amountMinor;

        if (0 === $reference) {
            return 0 === $document->amountMinor;
        }

        $delta = abs($document->amountMinor - $reference) / $reference;

        return $delta <= self::AMOUNT_TOLERANCE;
    }

    private function senderMatches(Service $service, string $sender): bool
    {
        $name = mb_strtolower(trim($service->getName()));
        $sender = mb_strtolower($sender);

        if ('' === $sender || '' === $name) {
            return false;
        }

        // El nombre del servicio aparece en la parte local del remitente
        // (`facturacion@ovh.com` para el servicio "OVH").
        return 1 === preg_match(sprintf('/\b%s\b/', preg_quote($name, '/')), $sender);
    }
}
