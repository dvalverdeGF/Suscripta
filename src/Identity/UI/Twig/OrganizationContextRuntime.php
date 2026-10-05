<?php

declare(strict_types=1);

namespace App\Identity\UI\Twig;

use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Enum\OrganizationRole;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Datos de organización que necesita el armazón de la aplicación.
 *
 * Se resuelven en un *runtime* y no en un global de Twig porque dependen del
 * usuario de la petición. El resultado se memoriza: la barra superior y el
 * selector de organización se pintan en todas las páginas y no queremos una
 * consulta por cada uno.
 */
final class OrganizationContextRuntime implements RuntimeExtensionInterface
{
    /** @var list<array{organization: Organization, role: OrganizationRole}>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly Security $security,
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * @return list<array{organization: Organization, role: OrganizationRole}>
     */
    public function organizations(): array
    {
        if (null !== $this->cache) {
            return $this->cache;
        }

        $user = $this->security->getUser();

        return $this->cache = $user instanceof User
            ? $this->organizations->findForUser($user)
            : [];
    }

    public function activeOrganizationId(): ?string
    {
        return $this->tenantContext->getOrganizationId()?->toRfc4122();
    }

    public function isActive(Organization $organization): bool
    {
        $active = $this->tenantContext->getOrganizationId();

        return $active instanceof Uuid && $active->equals($organization->getId());
    }
}
