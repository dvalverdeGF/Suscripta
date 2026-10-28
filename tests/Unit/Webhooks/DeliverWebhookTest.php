<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhooks;

use App\Mailbox\Application\CredentialCipherInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Webhooks\Application\DeliverWebhook;
use App\Webhooks\Application\Port\HostResolverInterface;
use App\Webhooks\Application\WebhookUrlGuard;
use App\Webhooks\Domain\Entity\Webhook;
use App\Webhooks\Domain\Entity\WebhookDelivery;
use App\Webhooks\Domain\Enum\DeliveryStatus;
use App\Webhooks\Domain\Enum\WebhookEvent;
use App\Webhooks\Domain\Repository\WebhookDeliveryRepositoryInterface;
use App\Webhooks\Domain\Repository\WebhookRepositoryInterface;
use App\Webhooks\Domain\Service\RetryPolicy;
use App\Webhooks\Domain\Service\WebhookSigner;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

/**
 * El contrato de una entrega de webhook (Fase 20).
 *
 * Se prueba con un cliente HTTP falso porque lo que hay que comprobar no es que
 * Suscripta sepa hacer un POST —eso lo hace Symfony— sino **qué** manda y
 * **qué** hace con la respuesta: la firma que viaja en la cabecera, la clave de
 * idempotencia que se repite en el reintento y la decisión de reintentar o
 * abandonar. Nada de eso se ve contra un receptor real, que solo diría «200».
 */
final class DeliverWebhookTest extends TestCase
{
    private const string NOW = '2026-10-28 12:00:00';
    private const string SECRET = 'whsec_c2VjcmV0by1kZS1wcnVlYmE';

    /**
     * Direcciones que devolverá el resolutor falso, por host.
     *
     * Ningún test de este fichero toca el DNS real: un test que dependa de un
     * registro de verdad falla el día que alguien lo cambia, y con un mapa se
     * prueban las direcciones privadas sin salir del proceso.
     *
     * @var array<string, list<string>>
     */
    private array $hostIps = [];

    private Uuid $organizationId;
    private Webhook $webhook;
    private WebhookDelivery $delivery;

    /**
     * @var list<array{url: string, options: array<string, mixed>}>
     */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->requests = [];
        $this->hostIps = ['erp.example.com' => ['93.184.216.34']];

        $this->webhook = new Webhook(
            organizationId: $this->organizationId,
            name: 'ERP',
            url: 'https://erp.example.com/hooks/suscripta',
            secretEncrypted: self::SECRET,
            events: [WebhookEvent::SERVICE_CREATED],
        );

        $this->delivery = new WebhookDelivery(
            organizationId: $this->organizationId,
            webhookId: $this->webhook->getId(),
            event: WebhookEvent::SERVICE_CREATED,
            payload: ['id' => 'abc', 'event' => 'service.created', 'data' => ['name' => 'Netflix']],
            idempotencyKey: 'abc',
            now: new DateTimeImmutable(self::NOW),
        );
    }

    public function testASuccessfulDeliveryMarksTheDeliveryAndTheSubscriber(): void
    {
        $deliver = $this->deliver(new MockResponse('', ['http_code' => 204]));

        ($deliver)($this->delivery->getId());

        self::assertSame(DeliveryStatus::DELIVERED, $this->delivery->getStatus());
        self::assertSame(204, $this->delivery->getResponseStatus());
        self::assertSame(1, $this->delivery->getAttempts());
        self::assertNotNull($this->delivery->getDeliveredAt());
        self::assertNotNull($this->webhook->getLastDeliveryAt());
    }

    public function testTheRequestCarriesTheSignatureAndTheIdempotencyKey(): void
    {
        $deliver = $this->deliver(new MockResponse('', ['http_code' => 200]));

        ($deliver)($this->delivery->getId());

        self::assertCount(1, $this->requests);

        $headers = $this->headersOf(0);
        $body = $this->requests[0]['options']['body'];

        self::assertSame('POST', $this->requests[0]['options']['method'] ?? 'POST');
        self::assertSame('abc', $headers['idempotency-key']);
        self::assertSame('service.created', $headers['x-suscripta-event']);
        self::assertStringContainsString('"name":"Netflix"', $body);

        $signer = new WebhookSigner();
        self::assertTrue(
            $signer->verify(self::SECRET, $headers[strtolower(WebhookSigner::HEADER)], $body, new DateTimeImmutable(self::NOW)->getTimestamp()),
            'La firma enviada tiene que validarse con el secreto del suscriptor.',
        );
    }

    public function testAFailedAttemptStaysPendingWithANextAttemptScheduled(): void
    {
        $deliver = $this->deliver(new MockResponse('', ['http_code' => 500]));

        ($deliver)($this->delivery->getId());

        self::assertSame(DeliveryStatus::PENDING, $this->delivery->getStatus());
        self::assertSame(500, $this->delivery->getResponseStatus());
        self::assertSame(1, $this->delivery->getAttempts());
        self::assertSame(
            '2026-10-28 12:00:30',
            $this->delivery->getNextAttemptAt()->format('Y-m-d H:i:s'),
        );
        self::assertNotNull($this->delivery->getLastError());
    }

    public function testARetryReusesTheSameBodyAndTheSameIdempotencyKey(): void
    {
        $deliver = $this->deliver(new MockResponse('', ['http_code' => 500]), new MockResponse('', ['http_code' => 200]));

        ($deliver)($this->delivery->getId());
        ($deliver)($this->delivery->getId());

        self::assertCount(2, $this->requests);
        self::assertSame($this->requests[0]['options']['body'], $this->requests[1]['options']['body']);
        self::assertSame(
            $this->headersOf(0)['idempotency-key'],
            $this->headersOf(1)['idempotency-key'],
            'Un reintento es el mismo evento: el receptor tiene que poder descartarlo.',
        );
        self::assertSame(DeliveryStatus::DELIVERED, $this->delivery->getStatus());
    }

    public function testAnAlreadyDeliveredRowIsNotSentAgain(): void
    {
        $deliver = $this->deliver(new MockResponse('', ['http_code' => 200]));

        ($deliver)($this->delivery->getId());
        ($deliver)($this->delivery->getId());

        self::assertCount(1, $this->requests, 'Un mensaje duplicado en la cola no puede repetir la entrega.');
    }

    public function testAPausedSubscriberAbandonsInsteadOfRetryingForever(): void
    {
        $this->webhook->setActive(false);

        $deliver = $this->deliver(new MockResponse('', ['http_code' => 200]));

        ($deliver)($this->delivery->getId());

        self::assertCount(0, $this->requests);
        self::assertSame(DeliveryStatus::ABANDONED, $this->delivery->getStatus());
    }

    public function testTheLastAttemptAbandonsInsteadOfScheduling(): void
    {
        $policy = new RetryPolicy();

        for ($i = 0; $i < $policy->maxDeliveries(); ++$i) {
            $deliver = $this->deliver(new MockResponse('', ['http_code' => 503]));
            ($deliver)($this->delivery->getId());
        }

        self::assertSame(DeliveryStatus::ABANDONED, $this->delivery->getStatus());
        self::assertSame($policy->maxDeliveries(), $this->delivery->getAttempts());
    }

    /**
     * Una URL que resuelve a una red interna no se llama: se abandona.
     *
     * Es la prueba de que la guarda anti-SSRF está **en el camino de la
     * entrega** y no solo en el formulario. Se comprueba además que no hay
     * petición: si la hubiera, el daño ya estaría hecho.
     */
    public function testAnInternalTargetIsAbandonedWithoutMakingTheRequest(): void
    {
        $this->hostIps = ['internal.example.com' => ['10.0.0.5']];
        $this->webhook->changeUrl('https://internal.example.com/hooks');

        $deliver = $this->deliver(new MockResponse('', ['http_code' => 200]));

        ($deliver)($this->delivery->getId());

        self::assertCount(0, $this->requests, 'No se puede hacer la petición ni una sola vez.');
        self::assertSame(DeliveryStatus::ABANDONED, $this->delivery->getStatus());
        self::assertStringContainsString('interna', (string) $this->delivery->getLastError());
    }

    /**
     * Un destino interno se abandona en el acto, sin agotar los ocho envíos.
     *
     * Reintentar durante siete días un error de configuración no lo arregla y
     * deja el historial lleno de ruido.
     */
    public function testAnInternalTargetIsNotRetried(): void
    {
        $this->hostIps = ['internal.example.com' => ['192.168.1.10']];
        $this->webhook->changeUrl('https://internal.example.com/hooks');

        $deliver = $this->deliver();

        ($deliver)($this->delivery->getId());

        self::assertSame(1, $this->delivery->getAttempts());
        self::assertSame(DeliveryStatus::ABANDONED, $this->delivery->getStatus());
    }

    /**
     * La petición se conecta a la dirección ya validada.
     *
     * Es lo que cierra la ventana del DNS rebinding: entre validar y conectar
     * el DNS podría devolver otra cosa.
     */
    public function testTheRequestIsPinnedToTheValidatedAddress(): void
    {
        $deliver = $this->deliver(new MockResponse('', ['http_code' => 200]));

        ($deliver)($this->delivery->getId());

        self::assertSame(
            ['erp.example.com' => '93.184.216.34'],
            $this->requests[0]['options']['resolve'] ?? null,
        );
        self::assertSame(0, $this->requests[0]['options']['max_redirects'] ?? null);
    }

    /**
     * Las cabeceras de una petición capturada, indexadas por nombre.
     *
     * El cliente HTTP de Symfony las entrega como una lista de cadenas
     * (`"nombre: valor"`), no como un mapa, así que aquí se vuelven a partir.
     *
     * @return array<string, string>
     */
    private function headersOf(int $index): array
    {
        $headers = [];

        foreach ($this->requests[$index]['options']['headers'] as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }

    private function deliver(MockResponse ...$responses): DeliverWebhook
    {
        $deliveries = $this->createMock(WebhookDeliveryRepositoryInterface::class);
        $deliveries->method('find')->willReturnCallback(
            fn (Uuid $id): ?WebhookDelivery => $id->equals($this->delivery->getId()) ? $this->delivery : null,
        );

        $webhooks = $this->createMock(WebhookRepositoryInterface::class);
        $webhooks->method('find')->willReturnCallback(
            fn (Uuid $id): ?Webhook => $id->equals($this->webhook->getId()) ? $this->webhook : null,
        );

        $cipher = $this->createMock(CredentialCipherInterface::class);
        $cipher->method('decrypt')->willReturnArgument(0);

        $queue = $responses;
        $requests = &$this->requests;

        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$queue, &$requests): MockResponse {
            $requests[] = ['url' => $url, 'options' => $options + ['method' => $method]];

            return array_shift($queue) ?? new MockResponse('', ['http_code' => 200]);
        });

        $hostIps = $this->hostIps;

        $resolver = new class($hostIps) implements HostResolverInterface {
            /**
             * @param array<string, list<string>> $hostIps
             */
            public function __construct(private readonly array $hostIps)
            {
            }

            public function resolve(string $host): array
            {
                return $this->hostIps[$host] ?? [];
            }
        };

        return new DeliverWebhook(
            $deliveries,
            $webhooks,
            $cipher,
            new WebhookSigner(),
            new RetryPolicy(),
            new WebhookUrlGuard($resolver),
            $client,
            new TenantContext(),
            new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
            new NullLogger(),
        );
    }
}
