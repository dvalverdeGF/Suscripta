<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\RegisterUser;
use App\Identity\Application\RequestPasswordReset;
use App\Identity\Domain\Entity\PasswordResetRequest;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\PasswordResetRequestRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

final class PasswordResetTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');
    }

    public function testRequestPageIsReachable(): void
    {
        $this->client->request('GET', '/reset-password');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Recuperar contraseña');
    }

    public function testRequestingAResetSendsAnEmailAndRedirectsToTheCheckPage(): void
    {
        $this->createUser('ada@example.com');

        $this->requestReset('ada@example.com');

        self::assertResponseRedirects('/reset-password/enviado');

        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('ada@example.com', $message->getTo()[0]->getAddress());
        self::assertStringContainsString('/reset-password/', (string) $message->getTextBody());
    }

    public function testRequestingAResetForAnUnknownEmailLooksTheSameButSendsNothing(): void
    {
        $this->requestReset('nadie@example.com');

        self::assertResponseRedirects('/reset-password/enviado');
        self::assertNull(self::getMailerMessage());
    }

    public function testTheTokenIsStoredHashedAndNeverInClear(): void
    {
        $user = $this->createUser('ada@example.com');
        $this->requestReset('ada@example.com');

        $token = $this->tokenFromLastEmail();
        $requests = self::getContainer()->get(PasswordResetRequestRepositoryInterface::class);

        self::assertNull($requests->findByTokenHash($token), 'El token en claro no debe estar en la base de datos.');

        $stored = $requests->findByTokenHash(RequestPasswordReset::hash($token));
        self::assertInstanceOf(PasswordResetRequest::class, $stored);
        self::assertTrue($stored->getUser()->getId()->equals($user->getId()));
    }

    public function testResettingThePasswordChangesItAndConsumesTheToken(): void
    {
        $user = $this->createUser('ada@example.com');
        $this->requestReset('ada@example.com');
        $token = $this->tokenFromLastEmail();

        $this->client->request('GET', '/reset-password/'.$token);
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Guardar contraseña', [
            'reset_password_form[plainPassword][first]' => 'NuevaClave!2026',
            'reset_password_form[plainPassword][second]' => 'NuevaClave!2026',
        ]);

        self::assertResponseRedirects('/login');

        $hasher = self::getContainer()->get('security.user_password_hasher');
        self::assertTrue($hasher->isPasswordValid($this->reload($user), 'NuevaClave!2026'));

        // El token es de un solo uso.
        $this->client->request('GET', '/reset-password/'.$token);
        $this->client->submitForm('Guardar contraseña', [
            'reset_password_form[plainPassword][first]' => 'OtraClave!2026',
            'reset_password_form[plainPassword][second]' => 'OtraClave!2026',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert--danger', 'ya se ha usado');
    }

    public function testRequestingASecondResetInvalidatesTheFirstLink(): void
    {
        $this->createUser('ada@example.com');

        $this->requestReset('ada@example.com');
        $first = $this->tokenFromLastEmail();

        $this->requestReset('ada@example.com');
        $second = $this->tokenFromLastEmail();

        self::assertNotSame($first, $second);

        $this->client->request('GET', '/reset-password/'.$first);
        $this->client->submitForm('Guardar contraseña', [
            'reset_password_form[plainPassword][first]' => 'NuevaClave!2026',
            'reset_password_form[plainPassword][second]' => 'NuevaClave!2026',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert--danger', 'ya se ha usado');
    }

    public function testAMalformedTokenDoesNotMatchTheRoute(): void
    {
        $this->client->request('GET', '/reset-password/no-es-un-token');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheResetFormRejectsMismatchedPasswords(): void
    {
        $this->createUser('ada@example.com');
        $this->requestReset('ada@example.com');
        $token = $this->tokenFromLastEmail();

        $this->client->request('GET', '/reset-password/'.$token);
        $this->client->submitForm('Guardar contraseña', [
            'reset_password_form[plainPassword][first]' => 'NuevaClave!2026',
            'reset_password_form[plainPassword][second]' => 'OtraClave!2026',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Las contraseñas no coinciden');
    }

    private function createUser(string $email): User
    {
        $registerUser = self::getContainer()->get(RegisterUser::class);

        return $registerUser($email, 'Sup3rSecret!2026', 'Ada Lovelace');
    }

    private function requestReset(string $email): void
    {
        $this->client->request('GET', '/reset-password');
        $this->client->submitForm('Enviar enlace', [
            'password_reset_request_form[email]' => $email,
        ]);
    }

    private function tokenFromLastEmail(): string
    {
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);

        $body = (string) $message->getTextBody();
        self::assertSame(1, preg_match('#/reset-password/([a-f0-9]{64})#', $body, $matches));

        if (!isset($matches[1])) {
            self::fail('El correo no contiene un enlace de restablecimiento.');
        }

        return $matches[1];
    }

    private function reload(User $user): User
    {
        $fresh = self::getContainer()->get(UserRepositoryInterface::class)->find($user->getId());
        self::assertInstanceOf(User::class, $fresh);

        return $fresh;
    }
}
