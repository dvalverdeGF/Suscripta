<?php

declare(strict_types=1);

namespace App\Discovery\Application;

use App\Catalog\Domain\Entity\Provider;
use App\Catalog\Domain\Repository\ProviderRepositoryInterface;
use App\Discovery\Application\Dto\BatchReviewResult;
use App\Discovery\Application\Dto\DiscoveryInput;
use App\Discovery\Application\Dto\ProposedData;
use App\Discovery\Domain\Entity\Discovery;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Exception\InvalidArgumentException;

use function is_string;
use function mb_strtolower;

use Symfony\Component\Uid\Uuid;

/**
 * Revisión por lotes de la bandeja de propuestas (ARCHITECTURE.md §13.11).
 *
 * Confirmar una a una una bandeja de veinte propuestas es la forma más rápida
 * de que el usuario deje de usarla. El lote confirma **exactamente lo que el
 * pipeline propuso**, sin tocar los datos: si algo hay que corregirlo, se
 * corrige en la pantalla de la propuesta concreta.
 *
 * Una propuesta que falla no aborta el lote: se cuenta y se sigue. Perder las
 * veinte porque la primera estaba ya confirmada sería peor que el error.
 */
final readonly class BatchReviewDiscoveries
{
    public function __construct(
        private ConfirmDiscovery $confirmDiscovery,
        private DismissDiscovery $dismissDiscovery,
        private ProviderRepositoryInterface $providers,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * @param list<Discovery> $discoveries
     */
    public function confirm(array $discoveries, ?Uuid $actorUserId = null): BatchReviewResult
    {
        $confirmed = 0;
        $failed = 0;

        foreach ($discoveries as $discovery) {
            try {
                $proposed = ProposedData::fromArray($discovery->getProposedData());
                $input = DiscoveryInput::fromProposal($proposed)
                    ->withProviderId($this->resolveProviderId($proposed->name()));

                ($this->confirmDiscovery)($discovery, $input, $actorUserId);
                ++$confirmed;
            } catch (InvalidArgumentException) {
                ++$failed;
            }
        }

        return new BatchReviewResult(confirmed: $confirmed, failed: $failed);
    }

    /**
     * @param list<Discovery> $discoveries
     */
    public function dismiss(array $discoveries, ?string $reason = null, ?Uuid $actorUserId = null): BatchReviewResult
    {
        $dismissed = 0;
        $failed = 0;

        foreach ($discoveries as $discovery) {
            try {
                ($this->dismissDiscovery)($discovery, $reason, $actorUserId);
                ++$dismissed;
            } catch (InvalidArgumentException) {
                ++$failed;
            }
        }

        return new BatchReviewResult(dismissed: $dismissed, failed: $failed);
    }

    /**
     * Busca el proveedor del catálogo por nombre o alias.
     *
     * En la revisión individual el usuario elige el proveedor de una lista. En
     * el lote no hay lista que elegir, así que se toma el que encaja por
     * nombre: es el mismo criterio que usa el resolutor del pipeline, y si no
     * hay coincidencia el servicio se queda sin proveedor, que es un estado
     * perfectamente válido.
     */
    private function resolveProviderId(?string $name): ?Uuid
    {
        if (null === $name) {
            return null;
        }

        $organizationId = $this->tenantContext->requireOrganizationId();
        $needle = mb_strtolower($name);

        foreach ($this->providers->findVisibleForOrganization($organizationId) as $provider) {
            if ($this->matches($provider, $needle)) {
                return $provider->getId();
            }
        }

        return null;
    }

    private function matches(Provider $provider, string $needle): bool
    {
        if (mb_strtolower($provider->getName()) === $needle) {
            return true;
        }

        foreach ($provider->getAliases() as $alias) {
            if (is_string($alias) && mb_strtolower($alias) === $needle) {
                return true;
            }
        }

        return false;
    }
}
