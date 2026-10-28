<?php

declare(strict_types=1);

namespace App\Tests\Functional\Website;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Enum\OrganizationPlan;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use App\Subscriptions\Domain\Entity\Plan;
use App\Subscriptions\Domain\Entity\PlanFeature;
use App\Subscriptions\Domain\Entity\PlanPrice;
use App\Subscriptions\Domain\Repository\PlanFeatureRepositoryInterface;
use App\Subscriptions\Domain\Repository\PlanPriceRepositoryInterface;
use App\Subscriptions\Domain\Repository\PlanRepositoryInterface;

use function count;
use function json_decode;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function random_int;
use function sprintf;
use function str_contains;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

/**
 * La web corporativa, recorrida como la recorre un visitante.
 *
 * La web vive en la misma aplicación que el panel, y esa convivencia es lo que
 * se puede romper sin que nadie se entere: una regla de `access_control` que
 * olvide una página nueva la deja en 403 para todo el mundo, y un cambio en el
 * catálogo puede dejar la página de precios vacía. Por eso el test pide las
 * rutas de verdad, sin sesión, y mira lo que devuelve.
 */
#[Group('website')]
final class PublicPagesTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        // El limitador del formulario de contacto se guarda en caché, que
        // sobrevive entre ejecuciones de la suite. Con una IP distinta por test
        // cada prueba empieza con el contador a cero sin bajar el límite real.
        $this->client->setServerParameter('REMOTE_ADDR', sprintf(
            '198.51.100.%d',
            random_int(1, 254),
        ));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function publicPages(): iterable
    {
        yield 'portada' => ['/', 'Cada recibo recurrente'];
        yield 'precios' => ['/precios', 'Gratis para probarlo de verdad'];
        yield 'producto' => ['/producto', 'Tres trabajos'];
        yield 'como funciona' => ['/como-funciona', 'Del correo al inventario'];
        yield 'integraciones' => ['/integraciones', 'Tres formas de meter datos'];
        yield 'seguridad' => ['/seguridad', 'tiene que explicarse'];
        yield 'preguntas frecuentes' => ['/preguntas-frecuentes', 'Lo que nos preguntan'];
        yield 'contacto' => ['/contacto', 'Escríbenos'];
        yield 'aviso legal' => ['/aviso-legal', 'Aviso legal'];
        yield 'privacidad' => ['/privacidad', 'Política de privacidad'];
        yield 'condiciones' => ['/condiciones', 'Condiciones del servicio'];
    }

    #[DataProvider('publicPages')]
    public function testEveryPublicPageOpensWithoutASession(string $path, string $heading): void
    {
        $crawler = $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $heading);

        // La web carga su propia hoja de estilo y no la del panel: si alguien
        // copia el `<head>` del backoffice, el visitante recibe Bootstrap.
        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href*="styles/site"]'));
        self::assertCount(0, $crawler->filter('link[rel="stylesheet"][href*="styles/backoffice.css"]'));
    }

    public function testTheHomepageSendsALoggedInUserToTheDashboard(): void
    {
        $this->client->loginUser(
            self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace'),
        );

        $this->client->request('GET', '/');

        self::assertResponseRedirects('/dashboard');
    }

    public function testTheDashboardIsStillPrivate(): void
    {
        $this->client->request('GET', '/dashboard');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testThePricingPagePublishesTheCatalogOfTheBackoffice(): void
    {
        $this->createPlan(OrganizationPlan::FREE, 'Gratis', 0, monthly: 0);
        $this->createPlan(OrganizationPlan::PRO, 'Profesional', 1, monthly: 1900, annual: 18240, highlighted: true);

        $this->client->request('GET', '/precios');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.plan--featured .plan__name', 'Profesional');
        self::assertSelectorTextContains('.plan--featured .plan__amount', '19,00');

        // La rebaja anual se calcula, no se escribe: 19,00 × 12 = 228,00 frente
        // a 182,40, que es un 20 % menos.
        self::assertSelectorTextContains('.plan--featured .plan__aside', 'Ahorra un 20 %');

        self::assertSelectorTextContains('.plan:not(.plan--featured) .plan__amount', '0 €');
    }

    public function testAPlanWithoutPriceIsAnnouncedInsteadOfShowingZero(): void
    {
        $this->createPlan(OrganizationPlan::PRO, 'Profesional', 0);

        $this->client->request('GET', '/precios');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.plan__amount', 'A consultar');
    }

    public function testAnEmptyCatalogDoesNotPromiseAnything(): void
    {
        $this->client->request('GET', '/precios');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->client->getCrawler()->filter('.plan'));
    }

    public function testTheContactFormSendsAnEmailToTheSiteInbox(): void
    {
        $this->submitContact('Quiero saber si cubrís dominios y certificados.');

        self::assertResponseRedirects('/contacto');

        // El correo se recoge antes de seguir la redirección: el registro de
        // envíos se vacía en cada petición.
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('ada@example.com', $message->getReplyTo()[0]->getAddress());
        self::assertStringContainsString('Analytical Engines', (string) $message->getTextBody());
        self::assertStringContainsString('dominios y certificados', (string) $message->getTextBody());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.notice--success', 'Mensaje enviado');
    }

    public function testTheContactFormRejectsAMessageWithoutConsent(): void
    {
        $this->submitContact('Sin consentimiento no debería salir de aquí.', consent: false);

        self::assertResponseStatusCodeSame(422);
        self::assertNull(self::getMailerMessage());
    }

    public function testTheContactFormStopsSomeoneWhoFloodsIt(): void
    {
        // El límite es de cinco mensajes por hora e IP (rate_limiter.yaml).
        for ($i = 1; $i <= 5; ++$i) {
            $this->submitContact('Mensaje número '.$i.' de una ráfaga que no debe pasar.');
            self::assertResponseRedirects('/contacto');
        }

        $this->submitContact('El sexto mensaje ya no debería salir.');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('.notice--error', 'varios mensajes seguidos');
    }

    public function testTheSitemapListsEveryPublicPageAndNothingElse(): void
    {
        $this->client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/xml; charset=UTF-8');

        $xml = (string) $this->client->getResponse()->getContent();

        $paths = ['/', '/producto', '/precios', '/como-funciona', '/integraciones', '/seguridad', '/preguntas-frecuentes', '/contacto', '/aviso-legal', '/privacidad', '/condiciones'];

        foreach ($paths as $path) {
            self::assertStringContainsString('<loc>https://suscripta.app'.$path.'</loc>', $xml);
        }

        self::assertStringNotContainsString('/dashboard', $xml);
        self::assertStringNotContainsString('/login', $xml);
    }

    public function testRobotsPointsToTheSitemapAndClosesThePrivateArea(): void
    {
        $this->client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Sitemap: https://suscripta.app/sitemap.xml', $body);
        self::assertStringContainsString('Disallow: /dashboard', $body);
    }

    public function testTheFaqPagePublishesStructuredDataWithTheSameQuestions(): void
    {
        $crawler = $this->client->request('GET', '/preguntas-frecuentes');

        self::assertResponseIsSuccessful();

        $scripts = $crawler->filter('script[type="application/ld+json"]')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'FAQPage'),
        );

        self::assertGreaterThan(0, $scripts->count());

        $data = json_decode($scripts->first()->text(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('FAQPage', $data['@type']);
        self::assertGreaterThanOrEqual(6, count($data['mainEntity']));
    }

    /**
     * Rellena y envía el formulario de contacto como lo haría un visitante.
     */
    private function submitContact(string $message, bool $consent = true): void
    {
        $crawler = $this->client->request('GET', '/contacto');

        $this->client->submit($crawler->selectButton('Enviar mensaje')->form([
            'contact_form[name]' => 'Ada Lovelace',
            'contact_form[email]' => 'ada@example.com',
            'contact_form[company]' => 'Analytical Engines',
            'contact_form[topic]' => 'producto',
            'contact_form[message]' => $message,
            'contact_form[consent]' => $consent,
        ]));
    }

    private function createPlan(
        OrganizationPlan $code,
        string $name,
        int $position,
        ?int $monthly = null,
        ?int $annual = null,
        bool $highlighted = false,
    ): Plan {
        $plan = new Plan($code, $name, $position);
        $plan->setActive(true);
        $plan->setHighlighted($highlighted);

        self::getContainer()->get(PlanRepositoryInterface::class)->save($plan);

        $prices = self::getContainer()->get(PlanPriceRepositoryInterface::class);

        if (null !== $monthly) {
            $prices->save(new PlanPrice($plan, Money::of($monthly, Currency::EUR), BillingPeriod::MONTHLY));
        }

        if (null !== $annual) {
            $prices->save(new PlanPrice($plan, Money::of($annual, Currency::EUR), BillingPeriod::ANNUAL));
        }

        self::getContainer()->get(PlanFeatureRepositoryInterface::class)->save(
            new PlanFeature($plan, 'Descubrimiento automático'),
        );

        return $plan;
    }
}
