<?php

declare(strict_types=1);

namespace App\Webhooks\Domain\Entity;

use App\Shared\Domain\Contract\TenantAwareInterface;
use App\Webhooks\Domain\Enum\DeliveryStatus;
use App\Webhooks\Domain\Enum\WebhookEvent;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Intento de entrega de un evento a un suscriptor (Fase 20).
 *
 * Se guarda **una fila por entrega**, no una por evento: un mismo evento
 * genera tantas filas como suscriptores suscritos, y cada una tiene su propio
 * ciclo de reintentos. Así un suscriptor caído no retrasa a los demás.
 *
 * El cuerpo (`payload`) se guarda **tal y como se envió**. Es lo que permite
 * reintentar exactamente lo mismo y lo que hace que la firma siga siendo
 * válida en el reintento: si se regenerara el cuerpo, el receptor podría
 * recibir dos cuerpos distintos para el mismo evento.
 *
 * `idempotencyKey` es estable entre reintentos y distinta entre entregas. El
 * receptor que la use puede descartar duplicados sin pensar: un reintento es
 * el mismo `idempotencyKey`, un evento nuevo no.
 */
#[ORM\Entity]
#[ORM\Table(name: 'webhook_delivery')]
#[ORM\Index(name: 'idx_webhook_delivery_webhook', columns: ['webhook_id'])]
#[ORM\Index(name: 'idx_webhook_delivery_status', columns: ['status', 'next_attempt_at'])]
#[ORM\UniqueConstraint(name: 'uniq_webhook_delivery_idempotency', columns: ['idempotency_key'])]
class WebhookDelivery implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    #[ORM\Column(name: 'webhook_id', type: 'uuid')]
    private Uuid $webhookId;

    #[ORM\Column(type: Types::STRING, length: 50, enumType: WebhookEvent::class)]
    private WebhookEvent $event;

    /**
     * Cuerpo exacto que se envía.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $payload;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: DeliveryStatus::class)]
    private DeliveryStatus $status = DeliveryStatus::PENDING;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $attempts = 0;

    #[ORM\Column(name: 'next_attempt_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $nextAttemptAt;

    #[ORM\Column(name: 'last_attempt_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastAttemptAt = null;

    /** Código HTTP de la última respuesta, si la hubo. */
    #[ORM\Column(name: 'response_status', type: Types::SMALLINT, nullable: true)]
    private ?int $responseStatus = null;

    /** Motivo del último fallo, recortado: es para diagnosticar, no para auditar. */
    #[ORM\Column(name: 'last_error', type: Types::STRING, length: 500, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'idempotency_key', type: Types::STRING, length: 64)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'delivered_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deliveredAt = null;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        Uuid $organizationId,
        Uuid $webhookId,
        WebhookEvent $event,
        array $payload,
        string $idempotencyKey,
        DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->webhookId = $webhookId;
        $this->event = $event;
        $this->payload = $payload;
        $this->idempotencyKey = $idempotencyKey;
        $this->createdAt = $now;
        $this->nextAttemptAt = $now;
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

    public function getWebhookId(): Uuid
    {
        return $this->webhookId;
    }

    public function getEvent(): WebhookEvent
    {
        return $this->event;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getStatus(): DeliveryStatus
    {
        return $this->status;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getNextAttemptAt(): DateTimeImmutable
    {
        return $this->nextAttemptAt;
    }

    public function getLastAttemptAt(): ?DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function getResponseStatus(): ?int
    {
        return $this->responseStatus;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDeliveredAt(): ?DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function markDelivered(int $responseStatus, DateTimeImmutable $at): void
    {
        ++$this->attempts;
        $this->status = DeliveryStatus::DELIVERED;
        $this->responseStatus = $responseStatus;
        $this->lastAttemptAt = $at;
        $this->deliveredAt = $at;
        $this->lastError = null;
    }

    /**
     * Registra un fallo y programa el siguiente intento.
     *
     * El estado **no** cambia a «fallido»: sigue pendiente con otra fecha. Solo
     * deja de estar pendiente cuando se abandona.
     */
    public function markFailed(?int $responseStatus, string $error, DateTimeImmutable $nextAttemptAt, DateTimeImmutable $at): void
    {
        ++$this->attempts;
        $this->responseStatus = $responseStatus;
        $this->lastAttemptAt = $at;
        $this->nextAttemptAt = $nextAttemptAt;
        $this->lastError = mb_substr($error, 0, 500);
    }

    /**
     * Da la entrega por perdida.
     *
     * El intento que provoca el abandono también cuenta: si no, el número de
     * intentos registrado no cuadraría con los envíos realmente hechos y la
     * política de reintentos parecería haberse quedado corta.
     */
    public function abandon(string $reason, DateTimeImmutable $at): void
    {
        ++$this->attempts;
        $this->status = DeliveryStatus::ABANDONED;
        $this->lastAttemptAt = $at;
        $this->lastError = mb_substr($reason, 0, 500);
    }
}
