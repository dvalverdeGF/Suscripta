<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Repository\OrganizationRepositoryInterface;

use function mb_substr;
use function strstr;

use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Convierte un nombre en el slug único de una organización.
 *
 * Vive aparte porque lo necesitan dos casos de uso: el alta de usuario (que
 * crea la organización personal) y la creación de una organización nueva
 * después de un borrado. Los dos necesitan exactamente la misma regla, y dos
 * copias de la misma regla divergen.
 */
final readonly class OrganizationSlug
{
    public function __construct(
        private SluggerInterface $slugger,
        private OrganizationRepositoryInterface $organizations,
    ) {
    }

    /**
     * Un slug legible, derivado del nombre, con el correo como respaldo para
     * los nombres que no tienen letras utilizables, y un sufijo numérico si
     * ya existe.
     */
    public function uniqueFrom(string $name, string $email): string
    {
        $base = $this->slugger->slug($name)->lower()->toString();

        if ('' === $base) {
            $base = $this->slugger->slug(strstr($email, '@', true) ?: 'organizacion')->lower()->toString();
        }

        $base = mb_substr($base, 0, 80);
        $slug = $base;
        $suffix = 2;

        while ($this->organizations->slugExists($slug)) {
            $slug = $base.'-'.$suffix;
            ++$suffix;
        }

        return $slug;
    }
}
