<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProfileTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');
    }

    public function testTheProfilePageShowsTheCurrentData(): void
    {
        $this->login('ada@example.com');

        $this->client->request('GET', '/settings/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ajustes de la cuenta');
        self::assertInputValueSame('profile_form[displayName]', 'Ada Lovelace');
        self::assertInputValueSame('profile_form[email]', 'ada@example.com');
        self::assertInputValueSame('profile_form[timezone]', 'Europe/Madrid');
    }

    public function testTheProfilePageWarnsWhenTheEmailIsNotVerified(): void
    {
        $this->login('ada@example.com');

        $this->client->request('GET', '/settings/profile');

        self::assertSelectorTextContains('.alert--warning', 'todavía no está confirmado');
    }

    public function testUpdatingTheProfilePersistsTheChanges(): void
    {
        $user = $this->login('ada@example.com');

        $this->client->request('GET', '/settings/profile');
        $this->client->submitForm('Guardar cambios', [
            'profile_form[displayName]' => 'Ada King',
            'profile_form[email]' => 'ada@example.com',
            'profile_form[locale]' => 'es',
            'profile_form[timezone]' => 'Atlantic/Canary',
        ]);

        self::assertResponseRedirects('/settings/profile');

        $fresh = $this->reload($user);
        self::assertSame('Ada King', $fresh->getDisplayName());
        self::assertSame('Atlantic/Canary', $fresh->getTimezone());
    }

    public function testChangingTheEmailUnverifiesTheAccountAndSendsANewLink(): void
    {
        $user = $this->login('ada@example.com');
        $user->markVerified();
        $this->save($user);

        $this->client->request('GET', '/settings/profile');
        $this->client->submitForm('Guardar cambios', [
            'profile_form[displayName]' => 'Ada Lovelace',
            'profile_form[email]' => 'ada.king@example.com',
            'profile_form[locale]' => 'es',
            'profile_form[timezone]' => 'Europe/Madrid',
        ]);

        self::assertResponseRedirects('/settings/profile');

        $fresh = $this->reload($user);
        self::assertSame('ada.king@example.com', $fresh->getEmail());
        self::assertFalse($fresh->isVerified(), 'Cambiar de correo debe invalidar la verificación anterior.');

        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('ada.king@example.com', $message->getTo()[0]->getAddress());
    }

    public function testChangingTheEmailToAnExistingOneIsRejected(): void
    {
        $registerUser = self::getContainer()->get(RegisterUser::class);
        $registerUser('grace@example.com', 'Sup3rSecret!2026', 'Grace Hopper');

        $user = $this->login('ada@example.com');

        $this->client->request('GET', '/settings/profile');
        $this->client->submitForm('Guardar cambios', [
            'profile_form[displayName]' => 'Ada Lovelace',
            'profile_form[email]' => 'grace@example.com',
            'profile_form[locale]' => 'es',
            'profile_form[timezone]' => 'Europe/Madrid',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__error', 'Ya existe una cuenta con este correo');
        self::assertSame('ada@example.com', $this->reload($user)->getEmail());
    }

    public function testAnInvalidTimezoneIsRejected(): void
    {
        $this->login('ada@example.com');

        $this->client->request('GET', '/settings/profile');
        $this->client->submitForm('Guardar cambios', [
            'profile_form[displayName]' => 'Ada Lovelace',
            'profile_form[email]' => 'ada@example.com',
            'profile_form[locale]' => 'es',
            'profile_form[timezone]' => 'Marte/Olympus',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__error', 'zona horaria no es válida');
    }

    public function testChangingThePasswordRequiresTheCurrentOne(): void
    {
        $user = $this->login('ada@example.com');

        $this->client->request('GET', '/settings/profile');
        $this->client->submitForm('Cambiar contraseña', [
            'change_password_form[currentPassword]' => 'NoEsLaBuena!2026',
            'change_password_form[plainPassword][first]' => 'NuevaClave!2026',
            'change_password_form[plainPassword][second]' => 'NuevaClave!2026',
        ]);

        self::assertResponseRedirects('/settings/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--danger', 'La contraseña actual no es correcta');

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($this->reload($user), 'Sup3rSecret!2026'));
    }

    public function testChangingThePasswordWithTheCurrentOneSucceeds(): void
    {
        $user = $this->login('ada@example.com');

        $this->client->request('GET', '/settings/profile');
        $this->client->submitForm('Cambiar contraseña', [
            'change_password_form[currentPassword]' => 'Sup3rSecret!2026',
            'change_password_form[plainPassword][first]' => 'NuevaClave!2026',
            'change_password_form[plainPassword][second]' => 'NuevaClave!2026',
        ]);

        self::assertResponseRedirects('/settings/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Contraseña actualizada');

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($this->reload($user), 'NuevaClave!2026'));
    }

    public function testThePasswordChangeRejectsMismatchedPasswords(): void
    {
        $user = $this->login('ada@example.com');

        $this->client->request('GET', '/settings/profile');
        $this->client->submitForm('Cambiar contraseña', [
            'change_password_form[currentPassword]' => 'Sup3rSecret!2026',
            'change_password_form[plainPassword][first]' => 'NuevaClave!2026',
            'change_password_form[plainPassword][second]' => 'OtraClave!2026',
        ]);

        self::assertResponseRedirects('/settings/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--danger', 'Las contraseñas no coinciden');

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($this->reload($user), 'Sup3rSecret!2026'));
    }

    private function login(string $email): User
    {
        $registerUser = self::getContainer()->get(RegisterUser::class);
        $user = $registerUser($email, 'Sup3rSecret!2026', 'Ada Lovelace');

        $this->client->loginUser($user);

        return $user;
    }

    private function reload(User $user): User
    {
        $fresh = self::getContainer()->get(UserRepositoryInterface::class)->find($user->getId());
        self::assertInstanceOf(User::class, $fresh);

        return $fresh;
    }

    private function save(User $user): void
    {
        self::getContainer()->get(UserRepositoryInterface::class)->save($user);
    }
}
