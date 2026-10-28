<?php

declare(strict_types=1);

namespace App\Webhooks\Domain\Entity;

use App\Shared\Domain\Contract\TenantAwareInterface;
use App\Webhooks\Domain\Enum\WebhookEvent;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function in_array;

use Symfony\Component\Uid\Uuid;

/**
 * Suscriptor de webhooks de una organización (Fase 20).
 *
 * Un suscriptor es **una URL**, no un usuario: el integrador da una dirección
 * y un conjunto de eventos, y Suscripta le avisa. Varios suscriptores por
 * organización son lo normal (uno para producción y otro para pruebas).
 *
 * ## El secreto
 *
 * Cada suscriptor tiene su propio secreto, distinto del de los demás y del de
 * las claves de API. Sirve para que el receptor pueda comprobar que la
 * petición viene de Suscripta: sin secreto propio, un suscriptor podría
 * falsificar entregas a otro.
 *
 * Se guarda **cifrado** (con el mismo cifrador que las credenciales de correo),
 * porque hay que poder leerlo para firmar cada entrega. Es la diferencia con
 * las claves de API, que se guardan como HMAC y no se pueden recuperar: aquí el
 * secreto se necesita en claro, así que la protección es el cifrado en reposo y
 * no la irreversibilidad.
 *
 * ## Aislamiento
 *
 * Es `TenantAwareInterface`: el filtro de Doctrine impide que una organización
 * vea o modifique los suscriptores de otra, y las entregas heredan el
 * aislamiento a través del suscriptor.
 */
#[ORM\Entity]
#[ORM\Table(name: 'webhook')]
#[ORM\Index(name: 'idx_webhook_organization', columns: ['organization_id'])]
class Webhook implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    /** Nombre que le da el operador: «Producción», «ERP de contabilidad»… */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $name;

    /** URL de destino. Solo `https` en producción (ver `WebhookUrl`). */
    #[ORM\Column(type: Types::STRING, length: 2048)]
    private string $url;

    /** Secreto en claro cifrado con el cifrador de credenciales. */
    #[ORM\Column(name: 'secret_encrypted', type: Types::TEXT)]
    private string $secretEncrypted;

    /**
     * Eventos suscritos, como texto (`["proposal.confirmed"]`).
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $events = [];

    /** Un suscriptor pausado no recibe nada, pero conserva su historial. */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'last_delivery_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastDeliveryAt = null;

    /**
     * @param list<WebhookEvent> $events
     */
    public function __construct(
        Uuid $organizationId,
        string $name,
        string $url,
        string $secretEncrypted,
        array $events,
    ) {
        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->name = $name;
        $this->url = $url;
        $this->secretEncrypted = $secretEncrypted;
        $this->setEvents($events);
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOrganizationId(): Uuid
    {
        return $this->organizationId;
    }

    public function setOrganizationId(Uuid $organizationId): void
    {
        $this->organizationId = $organizationId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Cambiar la URL no rota el secreto: el receptor sigue validando las
     * mismas firmas desde la dirección nueva. Rotarlo es una decisión aparte
     * (crear otro suscriptor) y no algo que pase de rebote.
     */
    public function changeUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getSecretEncrypted(): string
    {
        return $this->secretEncrypted;
    }

    /**
     * @return list<WebhookEvent>
     */
    public function getEvents(): array
    {
        $events = [];

        foreach ($this->events as $event) {
            $events[] = WebhookEvent::from($event);
        }

        return $events;
    }

    /**
     * @param list<WebhookEvent> $events
     */
    public function setEvents(array $events): void
    {
        $this->events = array_values(array_map(
            static fn (WebhookEvent $event): string => $event->value,
            $events,
        ));
    }

    public function isSubscribedTo(WebhookEvent $event): bool
    {
        return in_array($event->value, $this->events, true);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastDeliveryAt(): ?DateTimeImmutable
    {
        return $this->lastDeliveryAt;
    }

    public function markDelivered(DateTimeImmutable $at): void
    {
        $this->lastDeliveryAt = $at;
    }
}
