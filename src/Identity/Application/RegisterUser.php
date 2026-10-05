<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Entity\Membership;
use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Enum\OrganizationRole;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Exception\InvalidArgumentException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Alta de un usuario y creación de su organización personal.
 *
 * La organización personal se crea siempre, aunque el usuario no la use: así
 * el resto del sistema puede asumir que todo usuario tiene al menos una
 * organización activa (DECISIONS.md D-02).
 */
final readonly class RegisterUser
{
    public function __construct(
        private UserRepositoryInterface $users,
        private OrganizationRepositoryInterface $organizations,
        private UserPasswordHasherInterface $passwordHasher,
        private SluggerInterface $slugger,
    ) {
    }

    public function __invoke(string $email, string $plainPassword, string $displayName): User
    {
        $email = mb_strtolower(trim($email));

        if ($this->users->emailExists($email)) {
            throw new InvalidArgumentException('Ya existe una cuenta con este correo electrónico.');
        }

        $user = new User($email, '', $displayName);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $plainPassword));

        $organization = new Organization(
            name: $displayName,
            slug: $this->uniqueSlug($displayName, $email),
            personal: true,
        );

        new Membership($user, $organization, OrganizationRole::OWNER);

        $this->users->save($user, false);
        $this->organizations->save($organization, true);

        return $user;
    }

    private function uniqueSlug(string $displayName, string $email): string
    {
        $base = $this->slugger->slug($displayName)->lower()->toString();

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
