<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');
    }

    public function testRegistrationPageIsReachable(): void
    {
        $this->client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Crear cuenta');
    }

    public function testRegistrationCreatesUserAndOrganizationAndLogsIn(): void
    {
        $this->register('ada@example.com');

        self::assertResponseRedirects('/');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Panel');

        $users = self::getContainer()->get(UserRepositoryInterface::class);
        $user = $users->findByEmail('ada@example.com');

        self::assertInstanceOf(User::class, $user);
        self::assertSame('Ada Lovelace', $user->getDisplayName());
        self::assertCount(1, $user->getMemberships());
    }

    public function testRegistrationRejectsDuplicateEmail(): void
    {
        // Se crea el primer usuario por el caso de uso: el cliente HTTP queda
        // sin autenticar y puede volver a enviar el formulario.
        $registerUser = self::getContainer()->get(RegisterUser::class);
        $registerUser('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');

        $this->register('ada@example.com');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__error', 'Ya existe una cuenta');
    }

    public function testRegistrationRejectsMismatchedPasswords(): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Crear cuenta', [
            'registration_form[displayName]' => 'Ada Lovelace',
            'registration_form[email]' => 'ada@example.com',
            'registration_form[plainPassword][first]' => 'Sup3rSecret!2026',
            'registration_form[plainPassword][second]' => 'OtraCosa!2026',
            'registration_form[acceptTerms]' => '1',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Las contraseñas no coinciden');
    }

    private function register(string $email): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Crear cuenta', [
            'registration_form[displayName]' => 'Ada Lovelace',
            'registration_form[email]' => $email,
            'registration_form[plainPassword][first]' => 'Sup3rSecret!2026',
            'registration_form[plainPassword][second]' => 'Sup3rSecret!2026',
            'registration_form[acceptTerms]' => '1',
        ]);
    }
}
