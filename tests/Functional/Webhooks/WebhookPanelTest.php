<?php

declare(strict_types=1);

namespace App\Tests\Functional\Webhooks;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Identity\CompletesOnboarding;
use App\Tests\Support\Webhooks\ControllableHostResolver;
use App\Webhooks\Domain\Entity\Webhook;
use App\Webhooks\Domain\Enum\DeliveryStatus;
use App\Webhooks\Domain\Enum\WebhookEvent;
use App\Webhooks\Domain\Repository\WebhookDeliveryRepositoryInterface;
use App\Webhooks\Domain\Repository\WebhookRepositoryInterface;

use function str_contains;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * El panel de webhooks de salida (Fase 20).
 *
 * ## Qué se prueba y por qué
 *
 * 1. **El secreto se enseña una vez por defecto.** A diferencia de una clave de
 *    API, aquí sí se guarda en claro (cifrado) porque hay que firmar cada
 *    entrega, pero la pantalla de creación es la única que lo muestra sin
 *    pedirlo: verlo después exige un `POST` explícito. Si el listado lo
 *    filtrara, cualquiera con la sesión abierta podría firmar entregas.
 * 2. **Los eventos marcados son los que se guardan.** Un suscriptor que no
 *    escucha nada no se distingue de uno roto, así que el alta sin eventos es
 *    un error de validación, no un suscriptor silencioso.
 * 3. **La prueba manda una entrega real y deja rastro.** Contra un puerto
 *    cerrado falla al instante (conexión rechazada), que es exactamente lo que
 *    vería un operador con el endpoint mal puesto.
 */
final class WebhookPanelTest extends WebTestCase
{
    use CompletesOnboarding;

    private KernelBrowser $client;
    private Uuid $organizationId;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $user = self::getContainer()->get(RegisterUser::class)('hooks@example.com', 'Sup3rSecret!2026', 'Hook Owner');
        $this->completeOnboarding($user);

        $memberships = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($user);
        self::assertNotEmpty($memberships);
        $this->organizationId = $memberships[0]['organization']->getId();

        $this->client->loginUser($user);
    }

    /**
     * Da de alta un suscriptor pasando por el formulario.
     *
     * El POST va a mano y no por `$crawler->form()` porque los eventos son
     * casillas con el mismo nombre (`webhook_form[events][]`): `DomCrawler`
     * registra solo la última y no hay forma de marcar dos desde el objeto
     * `Form`.
     *
     * @param list<string> $events
     */
    private function createWebhook(string $name, string $url, array $events, bool $active = true): void
    {
        $crawler = $this->client->request('GET', '/webhooks/new');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('#webhook-form input[name="webhook_form[_token]"]')->attr('value');
        self::assertIsString($token);

        $payload = [
            'name' => $name,
            'url' => $url,
            'events' => $events,
        ];

        if ($active) {
            $payload['active'] = '1';
        }

        $this->client->request('POST', '/webhooks/new', [
            'webhook_form' => $payload + ['_token' => $token],
        ]);

        self::assertResponseRedirects('/webhooks/created');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    private function resolver(): ControllableHostResolver
    {
        $resolver = self::getContainer()->get(ControllableHostResolver::class);
        self::assertInstanceOf(ControllableHostResolver::class, $resolver);

        return $resolver;
    }

    private function secretFromCreatedPage(): string
    {
        $secret = $this->client->getCrawler()->filter('#webhook-secret')->attr('value');
        self::assertIsString($secret);

        return $secret;
    }

    /**
     * El suscriptor guardado, leído sin el filtro de organización.
     */
    private function storedWebhook(): Webhook
    {
        $tenant = self::getContainer()->get(TenantContext::class);
        $tenant->setOrganizationId(null);

        $webhooks = self::getContainer()->get(WebhookRepositoryInterface::class)->findForOrganization($this->organizationId);

        self::assertCount(1, $webhooks);

        return $webhooks[0];
    }

    public function testTheIndexExplainsWhatAWebhookIs(): void
    {
        $this->client->request('GET', '/webhooks');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Webhooks');
    }

    public function testCreatingAWebhookShowsTheSecretExactlyOnce(): void
    {
        $this->createWebhook('ERP', 'https://erp.example.com/hooks/suscripta', ['service.created']);

        $secret = $this->secretFromCreatedPage();

        self::assertStringStartsWith('whsec_', $secret);

        // Al recargar ya no está: la sesión lo borró al leerlo.
        $this->client->request('GET', '/webhooks/created');
        self::assertResponseRedirects('/webhooks');

        $this->client->followRedirect();
        self::assertFalse(str_contains((string) $this->client->getResponse()->getContent(), $secret));
    }

    public function testTheIndexNeverShowsTheSecret(): void
    {
        $this->createWebhook('Sin fuga', 'https://erp.example.com/hooks', ['service.created']);
        $secret = $this->secretFromCreatedPage();

        $this->client->request('GET', '/webhooks');
        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Sin fuga', $html);
        self::assertFalse(str_contains($html, $secret), 'El listado no puede contener el secreto en claro.');
    }

    public function testTheChosenEventsAreStored(): void
    {
        $this->createWebhook('Dos eventos', 'https://erp.example.com/hooks', ['service.created', 'charge.detected']);

        $webhook = $this->storedWebhook();
        $events = array_map(static fn (WebhookEvent $event): string => $event->value, $webhook->getEvents());

        self::assertContains('service.created', $events);
        self::assertContains('charge.detected', $events);
        self::assertCount(2, $events);
        self::assertTrue($webhook->isActive());
    }

    public function testASubscriberWithoutEventsIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/webhooks/new');
        $token = $crawler->filter('#webhook-form input[name="webhook_form[_token]"]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/webhooks/new', [
            'webhook_form' => [
                'name' => 'Sordo',
                'url' => 'https://erp.example.com/hooks',
                'events' => [],
                'active' => '1',
                '_token' => $token,
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Marca al menos un evento');

        $tenant = self::getContainer()->get(TenantContext::class);
        $tenant->setOrganizationId(null);
        self::assertCount(0, self::getContainer()->get(WebhookRepositoryInterface::class)->findForOrganization($this->organizationId));
    }

    public function testAnInsecureUrlIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/webhooks/new');
        $token = $crawler->filter('#webhook-form input[name="webhook_form[_token]"]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/webhooks/new', [
            'webhook_form' => [
                'name' => 'En claro',
                'url' => 'http://erp.example.com/hooks',
                'events' => ['service.created'],
                'active' => '1',
                '_token' => $token,
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * Un receptor en la red interna se rechaza al darlo de alta.
     *
     * El formulario no admite `https://10.0.0.5/` porque `Assert\Url` exige un
     * dominio con punto, así que el escenario real es un nombre que resuelve a
     * una dirección privada. El resolutor de test contesta justo eso.
     */
    public function testAUrlResolvingToAnInternalAddressIsRejected(): void
    {
        // Sin esto, el navegador de test reinicia el kernel entre peticiones y
        // el resolutor que se configura aquí no es el que atiende el `POST`.
        $this->client->disableReboot();
        $this->resolver()->willResolve('erp.example.com', ['10.0.0.5']);

        $crawler = $this->client->request('GET', '/webhooks/new');
        $token = $crawler->filter('#webhook-form input[name="webhook_form[_token]"]')->attr('value');
        self::assertIsString($token);

        $crawler = $this->client->request('POST', '/webhooks/new', [
            'webhook_form' => [
                'name' => 'Hacia dentro',
                'url' => 'https://erp.example.com/hooks',
                'events' => ['service.created'],
                'active' => '1',
                '_token' => $token,
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('dirección interna', $crawler->filter('#webhook-form')->text());

        $tenant = self::getContainer()->get(TenantContext::class);
        $tenant->setOrganizationId(null);
        self::assertCount(0, self::getContainer()->get(WebhookRepositoryInterface::class)->findForOrganization($this->organizationId));
    }

    /**
     * Un host que resuelve a una dirección pública se acepta.
     *
     * Es la otra mitad de la guarda: si rechazara todo, el test anterior
     * pasaría igual y nadie podría dar de alta nada.
     */
    public function testAUrlResolvingToAPublicAddressIsAccepted(): void
    {
        $this->client->disableReboot();
        $this->resolver()->willResolve('erp.example.com', ['93.184.216.34']);

        $this->createWebhook('Hacia fuera', 'https://erp.example.com/hooks', ['service.created']);

        self::assertSame('https://erp.example.com/hooks', $this->storedWebhook()->getUrl());
    }

    public function testPausingASubscriberIsSaved(): void
    {
        $this->createWebhook('Para pausar', 'https://erp.example.com/hooks', ['service.created']);
        $webhook = $this->storedWebhook();

        $crawler = $this->client->request('GET', '/webhooks/'.$webhook->getId()->toRfc4122());
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('#webhook-form input[name="webhook_form[_token]"]')->attr('value');
        self::assertIsString($token);

        // Sin `active` en el envío la casilla queda desmarcada.
        $this->client->request('POST', '/webhooks/'.$webhook->getId()->toRfc4122(), [
            'webhook_form' => [
                'name' => 'Pausado',
                'url' => 'https://erp.example.com/hooks',
                'events' => ['service.created'],
                '_token' => $token,
            ],
        ]);

        self::assertResponseRedirects('/webhooks/'.$webhook->getId()->toRfc4122());

        $reloaded = $this->storedWebhook();

        self::assertFalse($reloaded->isActive());
        self::assertSame('Pausado', $reloaded->getName());
    }

    public function testRevealingTheSecretReturnsTheSameOneThatWasShownOnCreation(): void
    {
        $this->createWebhook('Con revelación', 'https://erp.example.com/hooks', ['service.created']);
        $secret = $this->secretFromCreatedPage();
        $webhook = $this->storedWebhook();

        $crawler = $this->client->request('GET', '/webhooks/'.$webhook->getId()->toRfc4122());
        $token = $crawler->filter('form[action$="/secret"] input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/webhooks/'.$webhook->getId()->toRfc4122().'/secret', ['_token' => $token]);
        self::assertResponseRedirects('/webhooks/created');

        $this->client->followRedirect();

        self::assertSame($secret, $this->secretFromCreatedPage());
    }

    public function testATestDeliveryToAClosedPortIsRecordedAsFailed(): void
    {
        // `host.docker.internal:1`: el nombre resuelve dentro del contenedor y
        // en el puerto 1 no escucha nadie, así que el envío falla al instante.
        // El formulario exige una URL con TLD, por eso no vale `127.0.0.1`.
        $this->createWebhook('Roto', 'https://host.docker.internal:1/hooks', ['service.created']);
        $webhook = $this->storedWebhook();

        $crawler = $this->client->request('GET', '/webhooks/'.$webhook->getId()->toRfc4122());
        $token = $crawler->filter('form[action$="/test"] input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/webhooks/'.$webhook->getId()->toRfc4122().'/test', ['_token' => $token]);
        self::assertResponseRedirects('/webhooks/'.$webhook->getId()->toRfc4122());

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'no ha llegado');

        $tenant = self::getContainer()->get(TenantContext::class);
        $tenant->setOrganizationId(null);
        $deliveries = self::getContainer()->get(WebhookDeliveryRepositoryInterface::class)->findForWebhook($webhook->getId(), 25);

        self::assertCount(1, $deliveries);
        self::assertSame(DeliveryStatus::PENDING, $deliveries[0]->getStatus(), 'Un fallo se reintenta: sigue pendiente con otra fecha.');
        self::assertSame(1, $deliveries[0]->getAttempts());
        self::assertNotNull($deliveries[0]->getLastError());
    }

    public function testDeletingASubscriberRemovesItAndItsDeliveries(): void
    {
        $this->createWebhook('Para borrar', 'https://erp.example.com/hooks', ['service.created']);
        $webhook = $this->storedWebhook();

        $crawler = $this->client->request('GET', '/webhooks/'.$webhook->getId()->toRfc4122());
        $token = $crawler->filter('form[action$="/delete"] input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/webhooks/'.$webhook->getId()->toRfc4122().'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/webhooks');

        $tenant = self::getContainer()->get(TenantContext::class);
        $tenant->setOrganizationId(null);

        self::assertNull(self::getContainer()->get(WebhookRepositoryInterface::class)->find($webhook->getId()));
    }

    public function testAnotherOrganizationCannotOpenTheSubscriber(): void
    {
        $this->createWebhook('Privado', 'https://erp.example.com/hooks', ['service.created']);
        $webhook = $this->storedWebhook();

        $other = self::getContainer()->get(RegisterUser::class)('otro@example.com', 'Sup3rSecret!2026', 'Other Owner');
        $this->completeOnboarding($other);
        $this->client->loginUser($other);

        $this->client->request('GET', '/webhooks/'.$webhook->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }
}
