<?php

declare(strict_types=1);

namespace App\Webhooks\UI\Controller;

use App\Mailbox\Application\CredentialCipherInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Webhooks\Application\DeliverWebhook;
use App\Webhooks\Application\WebhookSecretGenerator;
use App\Webhooks\Domain\Entity\Webhook;
use App\Webhooks\Domain\Entity\WebhookDelivery;
use App\Webhooks\Domain\Enum\WebhookEvent;
use App\Webhooks\Domain\Repository\WebhookDeliveryRepositoryInterface;
use App\Webhooks\Domain\Repository\WebhookRepositoryInterface;
use App\Webhooks\UI\Form\WebhookFormData;
use App\Webhooks\UI\Form\WebhookFormType;

use const DATE_ATOM;

use function is_array;
use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Panel de webhooks de salida (Fase 20).
 *
 * ## El secreto se enseña una sola vez, pero se guarda
 *
 * Es la diferencia con las claves de API. Una clave de API se guarda como HMAC
 * y no se puede recuperar nunca; el secreto de un webhook hay que leerlo en
 * claro para firmar cada entrega, así que se guarda cifrado y **sí** se puede
 * volver a enseñar. Aun así, la pantalla de creación es la única que lo
 * muestra por defecto: verlo debería ser un acto consciente (botón
 * «mostrar»), no algo que aparece solo cada vez que se abre el listado.
 *
 * ## Por qué hay un botón de prueba
 *
 * Un suscriptor mal configurado es indistinguible de uno que no tiene nada que
 * contar hasta que pasa algo de verdad, y entonces ya es tarde. El botón manda
 * una entrega real, firmada de verdad, y enseña el código de respuesta: el
 * operador sabe en diez segundos si su endpoint valida la firma.
 */
#[Route('/webhooks', name: 'app_webhooks_')]
#[IsGranted('ROLE_USER')]
final class WebhookController extends AbstractController
{
    /**
     * El secreto en claro recién generado, hasta que se enseña una vez.
     *
     * Vive en la sesión y no en un flash porque un flash se borra al leerlo y
     * aquí interesa que sobreviva a una recarga accidental de la pestaña.
     */
    private const SESSION_SECRET = 'webhook.plain_secret';

    public function __construct(
        private readonly WebhookRepositoryInterface $webhooks,
        private readonly WebhookDeliveryRepositoryInterface $deliveries,
        private readonly WebhookSecretGenerator $secretGenerator,
        private readonly CredentialCipherInterface $cipher,
        private readonly DeliverWebhook $deliverWebhook,
        private readonly TenantContext $tenantContext,
        private readonly Clock $clock,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $organizationId = $this->tenantContext->requireOrganizationId();

        $rows = [];

        foreach ($this->webhooks->findForOrganization($organizationId) as $webhook) {
            $rows[] = [
                'webhook' => $webhook,
                'pending' => $this->deliveries->countPending($webhook->getId()),
            ];
        }

        return $this->render('backoffice/webhooks/index.html.twig', [
            'rows' => $rows,
            'events' => WebhookEvent::cases(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = new WebhookFormData();
        $form = $this->createForm(WebhookFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ([] === $data->events) {
                $form->get('events')->addError(new FormError('Marca al menos un evento: un suscriptor que no escucha nada no se distingue de uno roto.'));
            } else {
                $secret = $this->secretGenerator->generate();

                $webhook = new Webhook(
                    organizationId: $this->tenantContext->requireOrganizationId(),
                    name: (string) $data->name,
                    url: (string) $data->url,
                    secretEncrypted: $this->cipher->encrypt($secret),
                    events: $data->events,
                );
                $webhook->setActive($data->active);

                $this->webhooks->save($webhook);

                $request->getSession()->set(self::SESSION_SECRET, [
                    'secret' => $secret,
                    'name' => $webhook->getName(),
                    'id' => (string) $webhook->getId(),
                ]);

                $this->addFlash('success', sprintf('El suscriptor «%s» se ha creado.', $webhook->getName()));

                return $this->redirectToRoute('app_webhooks_created');
            }
        }

        return $this->render('backoffice/webhooks/new.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * El secreto recién generado, una única vez por defecto.
     */
    #[Route('/created', name: 'created', methods: ['GET'])]
    public function created(Request $request): Response
    {
        $session = $request->getSession();
        $created = $session->get(self::SESSION_SECRET);

        if (!is_array($created) || !isset($created['secret'], $created['name'], $created['id'])) {
            $this->addFlash('error', 'Ese secreto ya no está en la sesión. Puedes verlo de nuevo desde la ficha del suscriptor.');

            return $this->redirectToRoute('app_webhooks_index');
        }

        $session->remove(self::SESSION_SECRET);

        return $this->render('backoffice/webhooks/created.html.twig', [
            'secret' => (string) $created['secret'],
            'name' => (string) $created['name'],
            'id' => (string) $created['id'],
        ]);
    }

    #[Route('/{id}', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function edit(Uuid $id, Request $request): Response
    {
        $webhook = $this->findWebhook($id);

        $data = new WebhookFormData();
        $data->name = $webhook->getName();
        $data->url = $webhook->getUrl();
        $data->events = $webhook->getEvents();
        $data->active = $webhook->isActive();

        $form = $this->createForm(WebhookFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ([] === $data->events) {
                $form->get('events')->addError(new FormError('Marca al menos un evento.'));
            } else {
                $webhook->rename((string) $data->name);
                $webhook->changeUrl((string) $data->url);
                $webhook->setEvents($data->events);
                $webhook->setActive($data->active);
                $this->webhooks->save($webhook);

                $this->addFlash('success', 'Los cambios se han guardado.');

                return $this->redirectToRoute('app_webhooks_edit', ['id' => $id]);
            }
        }

        return $this->render('backoffice/webhooks/edit.html.twig', [
            'form' => $form,
            'webhook' => $webhook,
            'deliveries' => $this->deliveries->findForWebhook($webhook->getId(), 25),
        ]);
    }

    /**
     * Enseña el secreto de un suscriptor ya existente.
     *
     * Es una acción `POST` y no un enlace porque deja el secreto en la sesión:
     * un `GET` quedaría en el historial del navegador y en cualquier registro
     * de peticiones.
     */
    #[Route('/{id}/secret', name: 'reveal_secret', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function revealSecret(Uuid $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('reveal_webhook_secret', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        $webhook = $this->findWebhook($id);

        $request->getSession()->set(self::SESSION_SECRET, [
            'secret' => $this->cipher->decrypt($webhook->getSecretEncrypted()),
            'name' => $webhook->getName(),
            'id' => (string) $webhook->getId(),
        ]);

        return $this->redirectToRoute('app_webhooks_created');
    }

    /**
     * Manda una entrega real, ahora, y enseña cómo fue.
     *
     * Se ejecuta en la petición y no en la cola: el operador está mirando y
     * quiere el resultado ya. Un fallo aquí no rompe nada; queda en el
     * historial como una entrega más.
     */
    #[Route('/{id}/test', name: 'test', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function test(Uuid $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('test_webhook', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        $webhook = $this->findWebhook($id);
        $event = $webhook->getEvents()[0] ?? WebhookEvent::SERVICE_CREATED;
        $now = $this->clock->now();

        $delivery = new WebhookDelivery(
            organizationId: $webhook->getOrganizationId(),
            webhookId: $webhook->getId(),
            event: $event,
            payload: [
                'id' => 'test',
                'event' => $event->value,
                'occurredAt' => $now->format(DATE_ATOM),
                'data' => ['test' => true],
            ],
            idempotencyKey: 'test-'.Uuid::v7()->toRfc4122(),
            now: $now,
        );

        $this->deliveries->save($delivery);
        ($this->deliverWebhook)($delivery->getId());

        $status = $delivery->getStatus();

        if ($status->isDelivered()) {
            $this->addFlash('success', sprintf('Entrega de prueba correcta: el receptor respondió %d.', $delivery->getResponseStatus()));
        } else {
            $this->addFlash('error', sprintf(
                'La entrega de prueba no ha llegado: %s Se reintentará según la política de reintentos.',
                $delivery->getLastError() ?? 'sin detalle',
            ));
        }

        return $this->redirectToRoute('app_webhooks_edit', ['id' => $id]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function delete(Uuid $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('delete_webhook', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        $webhook = $this->findWebhook($id);
        $name = $webhook->getName();

        // Las entregas se borran primero: no tienen sentido sin el suscriptor y
        // la clave foránea lógica no las arrastraría.
        $this->deliveries->removeForWebhook($webhook->getId());
        $this->webhooks->remove($webhook);

        $this->addFlash('success', sprintf('El suscriptor «%s» se ha borrado.', $name));

        return $this->redirectToRoute('app_webhooks_index');
    }

    private function findWebhook(Uuid $id): Webhook
    {
        $webhook = $this->webhooks->find($id);

        if (null === $webhook) {
            throw $this->createNotFoundException('Ese suscriptor no existe.');
        }

        return $webhook;
    }
}
