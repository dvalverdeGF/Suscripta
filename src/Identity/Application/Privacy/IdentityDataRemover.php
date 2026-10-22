<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Privacy\OrganizationDataRemoverInterface;

use function count;

use Symfony\Component\Uid\Uuid;

/**
 * Borra la organización y sus membresías.
 *
 * Es el último en ejecutarse: hasta que no se borra el resto, la
 * organización sigue existiendo y sus módulos pueden seguir preguntando por
 * ella.
 *
 * Las **personas no se borran**: un usuario puede pertenecer a varias
 * organizaciones y su cuenta le pertenece a él, no a la organización. Lo que
 * se borra es su pertenencia a esta organización.
 */
final readonly class IdentityDataRemover implements OrganizationDataRemoverInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
    ) {
    }

    public function module(): string
    {
        return 'identity';
    }

    /**
     * El último: se lleva la organización y, con ella, las membresías.
     */
    public function priority(): int
    {
        return 80;
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        $organization = $this->organizations->find($organizationId);

        if (null === $organization) {
            return 0;
        }

        $members = $this->organizations->findMembers($organizationId);
        $removed = count($members);

        // La cascada de `Organization` arrastra las membresías.
        $this->organizations->remove($organization);

        return $removed;
    }
}
