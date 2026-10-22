<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Entity\Membership;
use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Enum\OrganizationRole;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\Exception\InvalidArgumentException;

use function mb_strlen;
use function trim;

/**
 * Crea una organización nueva y hace al usuario su propietario.
 *
 * Existe para dos momentos:
 *
 * 1. Un usuario registrado que quiere una organización **aparte** de su
 *    organización personal (una empresa, un cliente, una sociedad).
 * 2. Un usuario que ha borrado los datos de su organización (derecho de
 *    supresión, Fase 16): la borración se lleva la organización consigo, y
 *    sin una organización el backoffice no tiene nada que mostrar. Por eso
 *    esta pantalla se ofrece en lugar de dejar al usuario ante un error.
 */
final readonly class CreateOrganization
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
        private OrganizationSlug $slugs,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(User $user, string $name, bool $personal = false): Organization
    {
        $name = trim($name);

        if ('' === $name) {
            throw new InvalidArgumentException('El nombre de la organización no puede estar vacío.');
        }

        if (mb_strlen($name) > 120) {
            throw new InvalidArgumentException('El nombre de la organización es demasiado largo (máximo 120 caracteres).');
        }

        $organization = new Organization(
            name: $name,
            slug: $this->slugs->uniqueFrom($name, $user->getEmail()),
            personal: $personal,
        );

        new Membership($user, $organization, OrganizationRole::OWNER);

        $this->organizations->save($organization);

        $this->auditLogger->log(
            action: AuditAction::ORGANIZATION_CREATED,
            targetType: 'organization',
            targetId: $organization->getId()->toRfc4122(),
            organizationId: $organization->getId(),
            actorUserId: $user->getId(),
        );

        return $organization;
    }
}
