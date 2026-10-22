<?php

declare(strict_types=1);

namespace App\Identity\Application\Privacy;

use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Privacy\OrganizationDataProviderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Sección «identity» de la exportación: la organización y sus miembros.
 *
 * La organización es el contenedor, así que sus datos (nombre, slug, ajustes)
 * van en `meta` y las personas en `rows`: una fila por miembro, con lo que
 * la persona sabe de sí misma y con lo que la organización sabe de ella.
 *
 * No se exporta la contraseña ni el hash: eso no son datos del usuario, es
 * una credencial.
 */
final readonly class IdentityDataProvider implements OrganizationDataProviderInterface
{
    public function __construct(
        private OrganizationRepositoryInterface $organizations,
    ) {
    }

    public function section(): string
    {
        return 'identity';
    }

    public function export(Uuid $organizationId): array
    {
        $organization = $this->organizations->find($organizationId);
        $members = $this->organizations->findMembers($organizationId);

        $rows = [];

        foreach ($members as $member) {
            $user = $member['user'];

            $rows[] = [
                'email' => $user->getEmail(),
                'displayName' => $user->getDisplayName(),
                'role' => $member['role']->value,
                'joinedAt' => $member['joinedAt']->format('c'),
                'locale' => $user->getLocale(),
                'timezone' => $user->getTimezone(),
                'verified' => $user->isVerified(),
            ];
        }

        return [
            'meta' => [
                'name' => $organization?->getName(),
                'slug' => $organization?->getSlug(),
                'personal' => $organization?->isPersonal(),
                'createdAt' => $organization?->getCreatedAt()->format('c'),
                'settings' => $organization?->getSettings() ?? [],
            ],
            'rows' => $rows,
        ];
    }
}
