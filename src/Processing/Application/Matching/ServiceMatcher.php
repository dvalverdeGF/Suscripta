<?php

declare(strict_types=1);

namespace App\Processing\Application\Matching;

use function abs;

use App\Processing\Domain\Dto\ExtractedDocument;
use App\Processing\Domain\Dto\ServiceMatchResult;
use App\Processing\Domain\Matching\MatchConfidence;
use App\Processing\Domain\Matching\ServiceMatcherInterface;
use App\Services\Domain\Dto\ServiceView;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceViewRepositoryInterface;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;

use function mb_strtolower;
use function preg_match;
use function preg_quote;
use function sprintf;
use function str_contains;
use function strpos;
use function substr;

use Symfony\Component\Uid\Uuid;

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
 *
 * Lee el inventario por el **read model** (`ServiceView`) y no por la entidad:
 * el emparejamiento solo necesita consultar, y atarlo a la forma interna de
 * `Service` obligaría a tocar el pipeline cada vez que la entidad cambie
 * (ARCHITECTURE.md §3).
 */
final readonly class ServiceMatcher implements ServiceMatcherInterface
{
    public function __construct(
        private ServiceViewRepositoryInterface $services,
        private ServiceMatchWeights $weights,
    ) {
    }

    public function match(ExtractedDocument $document, ?Uuid $providerId = null): ServiceMatchResult
    {
        $candidates = $this->services->findAll(ServiceFilters::none());

        if ([] === $candidates) {
            return ServiceMatchResult::none();
        }

        $best = ServiceMatchResult::none();

        foreach ($candidates as $service) {
            $result = $this->scoreAgainst($service, $document, $providerId);

            if ($result->score > $best->score) {
                $best = $result;
            }
        }

        return $best;
    }

    private function scoreAgainst(
        ServiceView $service,
        ExtractedDocument $document,
        ?Uuid $providerId,
    ): ServiceMatchResult {
        $score = 0;
        $reasons = [];

        // La señal de proveedor es la más pesada del modelo, así que solo se
        // concede cuando hay **identidad**: el proveedor resuelto en el
        // catálogo tiene que ser exactamente el del servicio. Comparar nombres
        // —o simplemente suponer que «ambos tienen proveedor»— puntuaría igual
        // una factura de Netflix contra un servicio de OVH. Un proveedor
        // provisional (deducido por nombre, sin identidad) no puntúa: un nombre
        // adivinado no es una identidad.
        if (null !== $providerId && null !== $service->providerId && $providerId->equals($service->providerId)) {
            $score += $this->weights->provider;
            $reasons[] = [
                'signal' => 'Proveedor identificado',
                'weight' => $this->weights->provider,
                'detail' => $document->providerName,
            ];
        }

        if (null !== $document->senderDomain && $this->domainMatches($service, $document->senderDomain)) {
            $score += $this->weights->domain;
            $reasons[] = [
                'signal' => 'Dominio del remitente',
                'weight' => $this->weights->domain,
                'detail' => $document->senderDomain,
            ];
        }

        if (null !== $document->serviceName && $this->nameMatches($service->name, $document->serviceName)) {
            $score += $this->weights->name;
            $reasons[] = [
                'signal' => 'Nombre del servicio',
                'weight' => $this->weights->name,
                'detail' => $document->serviceName,
            ];
        }

        if (null !== $document->currency && $document->currency === $service->currency->value) {
            $score += $this->weights->currency;
            $reasons[] = [
                'signal' => 'Misma moneda',
                'weight' => $this->weights->currency,
                'detail' => $document->currency,
            ];
        }

        if (null !== $document->billingPeriod && $document->billingPeriod === $service->billingPeriod) {
            $score += $this->weights->periodicity;
            $reasons[] = [
                'signal' => 'Misma periodicidad',
                'weight' => $this->weights->periodicity,
                'detail' => $document->billingPeriod->label(),
            ];
        }

        if ($this->amountMatches($service, $document)) {
            $score += $this->weights->amount;
            $reasons[] = [
                'signal' => 'Importe compatible',
                'weight' => $this->weights->amount,
                'detail' => null !== $document->amountMinor && null !== $document->currency
                    ? Money::of($document->amountMinor, Currency::from($document->currency))->format()
                    : null,
            ];
        }

        if (null !== $document->sender && $this->senderMatches($service, $document->sender)) {
            $score += $this->weights->sender;
            $reasons[] = [
                'signal' => 'Remitente exacto ya visto',
                'weight' => $this->weights->sender,
                'detail' => $document->sender,
            ];
        }

        return ServiceMatchResult::forService(
            $service->id,
            $score,
            $reasons,
            MatchConfidence::fromScore($score, $this->weights->highThreshold, $this->weights->mediumThreshold),
        );
    }

    private function domainMatches(ServiceView $service, string $domain): bool
    {
        $name = mb_strtolower($service->name);
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

    private function amountMatches(ServiceView $service, ExtractedDocument $document): bool
    {
        if (null === $document->amountMinor || null === $document->currency) {
            return false;
        }

        $current = $service->currentAmount;

        if (null === $current || $current->currency->value !== $document->currency) {
            return false;
        }

        $reference = $current->amountMinor;

        if (0 === $reference) {
            return 0 === $document->amountMinor;
        }

        $delta = abs($document->amountMinor - $reference) / $reference;

        return $delta <= $this->weights->amountTolerance;
    }

    private function senderMatches(ServiceView $service, string $sender): bool
    {
        $name = mb_strtolower(trim($service->name));
        $sender = mb_strtolower($sender);

        if ('' === $sender || '' === $name) {
            return false;
        }

        // El nombre del servicio aparece en la parte local del remitente
        // (`facturacion@ovh.com` para el servicio "OVH").
        return 1 === preg_match(sprintf('/\b%s\b/', preg_quote($name, '/')), $sender);
    }
}
