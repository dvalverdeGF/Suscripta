<?php

declare(strict_types=1);

namespace App\Discovery\Application\Change;

use function abs;

use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Services\Domain\Dto\ServiceView;
use App\Services\Domain\Enum\ServiceStatus;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceViewRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;

use function count;
use function sprintf;

/**
 * Busca servicios que podrían ser el mismo payer dos veces (D-59).
 *
 * Esto es una **inferencia**, no un hecho: no hay ningún documento que diga
 * «estos dos servicios son el mismo». Lo único que hay son coincidencias en
 * el inventario, y una coincidencia puede ser una señal de que el usuario
 * se equivocó al dar de alta el servicio dos veces… o dos suscripciones
 * reales del mismo proveedor (por ejemplo, un plan personal y uno de empresa).
 *
 * Por eso el detector **no fusiona nada**: propone una sugerencia, explica
 * en qué se parecen y deja la decisión en manos de quien usa la aplicación.
 *
 * El modelo es ponderado y explicable, igual que el emparejamiento del
 * pipeline: cada señal que suma queda registrada con su peso, y la suma decide
 * si la pareja llega a proponerse o no.
 */
final readonly class DetectDuplicates
{
    /** Mismo proveedor identificado: la señal más fuerte. */
    private const int WEIGHT_PROVIDER = 45;

    /** Mismo importe (dentro de la tolerancia). */
    private const int WEIGHT_AMOUNT = 30;

    /** Misma periodicidad. */
    private const int WEIGHT_PERIOD = 15;

    /** Nombres casi iguales. */
    private const int WEIGHT_NAME = 40;

    /** Misma categoría: la más débil, solo desempata. */
    private const int WEIGHT_CATEGORY = 5;

    /**
     * Puntuación mínima para sospechar de un duplicado.
     *
     * Con este umbral, proveedor + importe (75), proveedor + nombres (85) e
     * importe + nombres (70) llegan; un nombre parecido solo (40) o un importe
     * parecido solo (30) no.
     */
    private const int THRESHOLD = 70;

    public function __construct(
        private ServiceViewRepositoryInterface $services,
        private DiscoveryRepositoryInterface $discoveries,
        private ChangeSettings $settings,
        private TenantContext $tenantContext,
        private Clock $clock,
    ) {
    }

    /**
     * @return list<Discovery>
     */
    public function detect(): array
    {
        $candidates = array_values(array_filter(
            $this->services->findAll(ServiceFilters::none()),
            static fn (ServiceView $service): bool => ServiceStatus::ACTIVE === $service->status,
        ));

        $proposals = [];
        $total = count($candidates);

        for ($i = 0; $i < $total; ++$i) {
            for ($j = $i + 1; $j < $total; ++$j) {
                $proposal = $this->compare($candidates[$i], $candidates[$j]);

                if (null !== $proposal) {
                    $proposals[] = $proposal;
                }
            }
        }

        return $proposals;
    }

    private function compare(ServiceView $left, ServiceView $right): ?Discovery
    {
        $score = 0;
        $reasons = [];

        if ($this->sameProvider($left, $right)) {
            $score += self::WEIGHT_PROVIDER;
            $reasons[] = ['signal' => 'Mismo proveedor', 'weight' => self::WEIGHT_PROVIDER, 'detail' => null];
        }

        if ($this->sameAmount($left, $right)) {
            $score += self::WEIGHT_AMOUNT;
            $reasons[] = ['signal' => 'Mismo importe', 'weight' => self::WEIGHT_AMOUNT, 'detail' => null];
        }

        if ($left->billingPeriod === $right->billingPeriod) {
            $score += self::WEIGHT_PERIOD;
            $reasons[] = [
                'signal' => 'Misma periodicidad',
                'weight' => self::WEIGHT_PERIOD,
                'detail' => $left->billingPeriod->label(),
            ];
        }

        $similarity = ServiceSimilarity::similarity($left->name, $right->name);

        if ($similarity >= $this->settings->duplicateNameSimilarity) {
            $score += self::WEIGHT_NAME;
            $reasons[] = [
                'signal' => 'Nombres muy parecidos',
                'weight' => self::WEIGHT_NAME,
                'detail' => sprintf('%d %% de similitud', $similarity),
            ];
        }

        if ($this->sameCategory($left, $right)) {
            $score += self::WEIGHT_CATEGORY;
            $reasons[] = ['signal' => 'Misma categoría', 'weight' => self::WEIGHT_CATEGORY, 'detail' => null];
        }

        if ($score < self::THRESHOLD) {
            return null;
        }

        // El más antiguo es el original y el más reciente el sospechoso: si
        // resulta ser un duplicado, el que se cancela es el nuevo, porque el
        // viejo es el que tiene el histórico.
        [$original, $suspect] = $left->createdAt <= $right->createdAt
            ? [$left, $right]
            : [$right, $left];

        $proposedData = [
            'serviceId' => $original->id->toRfc4122(),
            'relatedServiceId' => $suspect->id->toRfc4122(),
            'serviceName' => $original->name,
            'relatedServiceName' => $suspect->name,
            'relatedPlanName' => $suspect->planName,
            'currency' => $original->currency->value,
            'amountMinor' => $original->currentAmount?->amountMinor,
            'relatedAmountMinor' => $suspect->currentAmount?->amountMinor,
            'score' => $score,
        ];

        $dedupKey = Discovery::buildDedupKey(DiscoveryType::DUPLICATE, $proposedData);

        // El barrido es idempotente: si ya se propuso esta pareja —porque el
        // usuario la rechazó o porque ya la confirmó— no se vuelve a preguntar.
        if (null !== $this->discoveries->findByDedupKey($dedupKey)) {
            return null;
        }

        $discovery = new Discovery(
            organizationId: $this->tenantContext->requireOrganizationId(),
            type: DiscoveryType::DUPLICATE,
            detectedAt: $this->clock->now(),
            proposedData: $proposedData,
        );

        $discovery->applyMatch($score, $reasons, $original->id);
        $discovery->applyConfidence($score);
        $discovery->markAsInference();

        $this->discoveries->save($discovery);

        return $discovery;
    }

    private function sameProvider(ServiceView $left, ServiceView $right): bool
    {
        if (null === $left->providerId || null === $right->providerId) {
            return false;
        }

        return $left->providerId->equals($right->providerId);
    }

    private function sameCategory(ServiceView $left, ServiceView $right): bool
    {
        if (null === $left->categoryId || null === $right->categoryId) {
            return false;
        }

        return $left->categoryId->equals($right->categoryId);
    }

    private function sameAmount(ServiceView $left, ServiceView $right): bool
    {
        $a = $left->currentAmount;
        $b = $right->currentAmount;

        if (null === $a || null === $b) {
            return false;
        }

        if ($a->currency !== $b->currency) {
            return false;
        }

        $reference = $a->amountMinor;

        if (0 === $reference) {
            return 0 === $b->amountMinor;
        }

        $delta = abs($b->amountMinor - $reference) / $reference;

        return $delta <= $this->settings->duplicateAmountTolerance;
    }
}
