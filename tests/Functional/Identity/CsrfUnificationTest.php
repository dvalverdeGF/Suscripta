<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El CSRF es uno solo y vive en la sesión (Fase 25, D-91).
 *
 * ## Cómo se comprueba, y por qué no comparando cadenas
 *
 * Desde Symfony 8 el gestor de sesión **enmascara** el token en cada render:
 * guarda un valor en la sesión y sirve `clave.valor` con una clave aleatoria,
 * de modo que el HTML nunca lleva el secreto literal. Dos renders seguidos dan
 * cadenas distintas aunque el token sea el mismo. Comparar el HTML, por tanto,
 * no dice nada; lo que dice algo es **mirar la sesión** y comprobar que el
 * token sirve después de otras peticiones.
 *
 * Con tokens sin estado (`stateless_token_ids`) no habría nada en la sesión y
 * además se pondría una cookie `csrf-token`: eso es lo que se descarta aquí.
 */
final class CsrfUnificationTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');
    }

    public function testTheTokenIsStoredInTheSession(): void
    {
        $this->login('ada@example.com');

        $this->resendToken();

        /** @var array<string, mixed> $attributes */
        $attributes = $this->client->getRequest()->getSession()->all();

        self::assertArrayHasKey('_csrf/resend_verification', $attributes);
        self::assertIsString($attributes['_csrf/resend_verification']);
        self::assertNotSame('', $attributes['_csrf/resend_verification']);
    }

    public function testNoStatelessCookieIsIssued(): void
    {
        $this->login('ada@example.com');
        $this->resendToken();

        self::assertNull(
            $this->client->getCookieJar()->get('csrf-token'),
            'Un token sin estado dejaría una cookie; el de sesión no deja ninguna.',
        );
    }

    public function testATokenFromAnEarlierPageStillWorksAfterOtherRequests(): void
    {
        $this->login('ada@example.com');

        // Como una pestaña abierta hace rato: el token se lee, se navega, y
        // luego se envía. Si el token caducara en cada respuesta, esto fallaría.
        $token = $this->resendToken();
        $this->client->request('GET', '/dashboard');

        $this->client->request('POST', '/verify/email/resend', ['_token' => $token]);

        self::assertResponseRedirects('/dashboard');
    }

    public function testTheTokenOfTheSamePurposeIsAccepted(): void
    {
        $this->login('ada@example.com');

        $this->client->request('POST', '/verify/email/resend', ['_token' => $this->resendToken()]);

        self::assertResponseRedirects('/dashboard');
    }

    public function testATokenOfAnotherPurposeIsRejected(): void
    {
        $this->login('ada@example.com');

        // El token del cierre de sesión no sirve para pedir otro correo.
        $this->client->request('GET', '/settings/profile');

        $logout = $this->client->getCrawler()
            ->filter('input[name="_csrf_token"]')
            ->attr('value');

        self::assertIsString($logout);

        $this->client->request('POST', '/verify/email/resend', ['_token' => $logout]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnInventedTokenIsRejected(): void
    {
        $this->login('ada@example.com');

        $this->client->request('POST', '/verify/email/resend', ['_token' => str_repeat('a', 32)]);

        self::assertResponseStatusCodeSame(403);
    }

    private function login(string $email): User
    {
        /** @var User $user */
        $user = self::getContainer()->get(RegisterUser::class)($email, 'Sup3rSecret!2026', 'Ada Lovelace');

        $this->client->loginUser($user);

        return $user;
    }

    private function resendToken(): string
    {
        $this->client->request('GET', '/settings/profile');

        $token = $this->client->getCrawler()
            ->filter('form[action="/verify/email/resend"] input[name="_token"]')
            ->attr('value');

        self::assertIsString($token, 'No se ha encontrado el token de reenviar la confirmación.');

        return $token;
    }
}
