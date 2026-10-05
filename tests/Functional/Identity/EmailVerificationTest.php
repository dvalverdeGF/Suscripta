<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\RegisterUser;
use App\Identity\Application\SendVerificationEmail;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use DateInterval;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

final class EmailVerificationTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');
    }

    public function testNewAccountsStartUnverified(): void
    {
        $user = $this->createUser('ada@example.com');

        self::assertFalse($user->isVerified());
    }

    public function testVerificationLinkMarksTheAccountAsVerified(): void
    {
        $user = $this->createUser('ada@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', $this->verificationUrl($user));

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Correo confirmado');

        self::assertTrue($this->reload($user)->isVerified());
    }

    public function testVerificationLinkIsRejectedWhenTheSignatureIsTamperedWith(): void
    {
        $user = $this->createUser('ada@example.com');
        $this->client->loginUser($user);

        $tampered = preg_replace('/_hash=[^&]+/', '_hash=0000000000000000000000000000000000000000000', $this->verificationUrl($user));
        self::assertIsString($tampered);

        $this->client->request('GET', $tampered);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--danger', 'no es válido o ha caducado');

        self::assertFalse($this->reload($user)->isVerified());
    }

    public function testVerificationLinkIsRejectedForAnUnknownUser(): void
    {
        $this->client->request('GET', $this->signedUrl('0199f1c2-0000-7000-8000-000000000000'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testVerificationLinkIsRejectedWhenTheIdIsNotAUuid(): void
    {
        $this->client->request('GET', $this->signedUrl('no-soy-un-uuid'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testVerifyingAnAlreadyVerifiedAccountIsIdempotent(): void
    {
        $user = $this->createUser('ada@example.com');
        $this->client->loginUser($user);
        $url = $this->verificationUrl($user);

        $this->client->request('GET', $url);
        $this->client->followRedirect();

        $this->client->request('GET', $url);
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--info', 'ya estaba confirmado');
    }

    public function testResendSendsANewEmail(): void
    {
        $user = $this->createUser('ada@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/');
        $this->client->submitForm('Reenviar el correo');

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Te hemos enviado un correo nuevo');
    }

    public function testResendIsRejectedWithoutAValidCsrfToken(): void
    {
        $user = $this->createUser('ada@example.com');
        $this->client->loginUser($user);

        $this->client->request('POST', '/verify/email/resend', ['_token' => 'inventado']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheVerificationEmailContainsASignedAbsoluteLink(): void
    {
        $user = $this->createUser('ada@example.com');

        $sendVerificationEmail = self::getContainer()->get(SendVerificationEmail::class);
        $sendVerificationEmail($user);

        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('ada@example.com', $message->getTo()[0]->getAddress());
        self::assertStringContainsString('Confirma tu correo', (string) $message->getSubject());

        $body = (string) $message->getTextBody();
        self::assertStringContainsString('/verify/email?', $body);
        self::assertStringContainsString('id='.$user->getId()->toRfc4122(), $body);
        self::assertStringContainsString('_hash=', $body);
    }

    public function testTheVerificationEmailIsNotSentTwiceForAVerifiedAccount(): void
    {
        $user = $this->createUser('ada@example.com');
        $user->markVerified();
        $this->save($user);

        $sendVerificationEmail = self::getContainer()->get(SendVerificationEmail::class);
        $sendVerificationEmail($user);

        self::assertNull(self::getMailerMessage());
    }

    private function createUser(string $email): User
    {
        $registerUser = self::getContainer()->get(RegisterUser::class);

        return $registerUser($email, 'Sup3rSecret!2026', 'Ada Lovelace');
    }

    private function verificationUrl(User $user): string
    {
        return $this->signedUrl($user->getId()->toRfc4122());
    }

    private function signedUrl(string $id): string
    {
        $router = self::getContainer()->get(RouterInterface::class);
        $signer = self::getContainer()->get(UriSigner::class);

        $url = $router->generate(
            'app_verify_email',
            ['id' => $id],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $signer->sign($url, new DateInterval('PT24H'));
    }

    private function reload(User $user): User
    {
        $users = self::getContainer()->get(UserRepositoryInterface::class);
        $fresh = $users->find($user->getId());

        self::assertInstanceOf(User::class, $fresh);

        return $fresh;
    }

    private function save(User $user): void
    {
        self::getContainer()->get(UserRepositoryInterface::class)->save($user);
    }
}
