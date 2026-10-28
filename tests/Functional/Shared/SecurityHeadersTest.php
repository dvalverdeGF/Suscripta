<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Tests\Support\Identity\CompletesOnboarding;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cabeceras de seguridad HTTP (Fase 16).
 *
 * Se prueban por HTTP porque lo que importa es lo que sale por el cable: una
 * cabecera que se pierde en un proxy o en un controlador no protege nada.
 *
 * La CSP con nonce se prueba con las dos mitades: la cabecera declara el
 * nonce y la página lo usa en sus `<script>`. Si el HTML llevara un script en
 * línea sin el nonce, el navegador lo bloquearía y la aplicación dejaría de
 * funcionar; si la cabecera no lo declarara, el navegador lo bloquearía
 * igualmente. Las dos mitades tienen que casar.
 */
final class SecurityHeadersTest extends WebTestCase
{
    use CompletesOnboarding;

    public function testEveryResponseCarriesTheSecurityHeaders(): void
    {
        $client = self::createClient();

        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();

        $headers = $client->getResponse()->headers;

        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));
        self::assertSame('same-origin', $headers->get('Cross-Origin-Opener-Policy'));
        self::assertStringContainsString('geolocation=()', (string) $headers->get('Permissions-Policy'));
    }

    public function testTheContentSecurityPolicyIsPresentAndRestrictsTheApplication(): void
    {
        $client = self::createClient();

        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');

        self::assertStringContainsString("default-src 'self'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString("form-action 'self'", $csp);
        self::assertStringContainsString("base-uri 'self'", $csp);

        // El `script-src` solo admite scripts del propio sitio con el nonce
        // de esta respuesta: nada de scripts en línea sin más.
        self::assertStringContainsString("script-src 'self' 'nonce-", $csp);
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
    }

    public function testTheNonceOfTheHeaderIsTheOneUsedByThePage(): void
    {
        $client = $this->clientWithUser();

        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        $content = (string) $client->getResponse()->getContent();

        $nonce = $this->nonceOf($csp);
        self::assertNotSame('', $nonce, 'La CSP no declara ningún nonce.');

        self::assertStringContainsString(sprintf('nonce="%s"', $nonce), $content);
    }

    public function testEachResponseGetsItsOwnNonce(): void
    {
        $client = self::createClient();

        $client->request('GET', '/login');
        $first = $this->nonceOf($client->getResponse()->headers->get('Content-Security-Policy'));

        $client->request('GET', '/login');
        $second = $this->nonceOf($client->getResponse()->headers->get('Content-Security-Policy'));

        self::assertNotSame('', $first);
        self::assertNotSame('', $second);
        self::assertNotSame($first, $second);
    }

    public function testTheHeadersAreAlsoSentOnTheErrorPages(): void
    {
        $client = self::createClient();

        $client->request('GET', '/esta-pagina-no-existe');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));
    }

    public function testTheErrorPageHasNoContentSecurityPolicyInDebugMode(): void
    {
        // Symfony quita la CSP de las páginas de error cuando el kernel está
        // en modo debug: la página de excepciones del profiler trae scripts
        // en línea que la CSP bloquearía. En producción (`debug = false`) la
        // CSP se mantiene, y eso es lo que se comprueba en el test de
        // producción equivalente.
        $client = self::createClient();

        $client->request('GET', '/esta-pagina-no-existe');

        self::assertResponseStatusCodeSame(404);
        self::assertNull($client->getResponse()->headers->get('Content-Security-Policy'));
    }

    public function testTheStrictTransportSecurityIsOnlySentOverHttps(): void
    {
        $client = self::createClient();

        $client->request('GET', '/login');

        self::assertNull($client->getResponse()->headers->get('Strict-Transport-Security'));

        $client->request('GET', '/login', [], [], ['HTTPS' => 'on']);

        self::assertStringContainsString(
            'max-age=31536000',
            (string) $client->getResponse()->headers->get('Strict-Transport-Security'),
        );
    }

    public function testTheAuthenticatedPagesAlsoCarryTheHeaders(): void
    {
        $client = $this->clientWithUser();

        $client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));
        self::assertNotNull($client->getResponse()->headers->get('Content-Security-Policy'));
    }

    private function clientWithUser(): KernelBrowser
    {
        $client = self::createClient();

        $registerUser = self::getContainer()->get(RegisterUser::class);
        $user = $registerUser('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        self::assertInstanceOf(User::class, $user);
        $this->completeOnboarding($user);
        $client->loginUser($user);

        return $client;
    }

    private function nonceOf(?string $csp): string
    {
        if (null === $csp) {
            return '';
        }

        preg_match("/'nonce-([a-f0-9]+)'/", $csp, $matches);

        return $matches[1] ?? '';
    }
}
