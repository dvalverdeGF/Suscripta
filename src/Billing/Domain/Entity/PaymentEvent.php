<?php

declare(strict_types=1);

namespace App\Billing\Domain\Entity;

use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function sprintf;

use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * Un evento de la pasarela ya aplicado (Fase 24).
 *
 * ## Para qué existe esta tabla
 *
 * Un webhook **no es una notificación, es un reintento con fecha de caducidad**:
 * la pasarela reenvía el mismo evento hasta que recibe un 200, y nada impide que
 * lleguen dos en paralelo. Sin registro, el segundo `invoice.paid` renovaría el
 * periodo otra vez y el cliente vería dos meses cobrados por uno.
 *
 * La clave de idempotencia es el identificador del evento en la pasarela, y es
 * **único en la base de datos**, no una comprobación en PHP: dos procesos a la
 * vez solo pueden insertar uno, y el segundo recibe una violación de unicidad
 * que se traduce en «ya estaba». Comprobar primero y escribir después deja una
 * ventana por la que se cuelan justo los duplicados que llegan a la vez.
 *
 * ## Se guarda el tipo y no el cuerpo
 *
 * El cuerpo de un evento contiene datos personales del cliente (nombre, correo,
 * dirección de facturación) que Suscripta no necesita conservar. Se guarda qué
 * evento fue y cuándo, que es lo que sirve para depurar, y nada más.
 */
#[ORM\Entity]
#[ORM\Table(name: 'payment_event')]
#[ORM\UniqueConstraint(name: 'uniq_payment_event_external', columns: ['external_event_id'])]
#[ORM\Index(name: 'idx_payment_event_created', columns: ['created_at'])]
class PaymentEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * El `evt_…` de la pasarela. Único: es la clave de idempotencia.
     */
    #[ORM\Column(name: 'external_event_id', type: Types::STRING, length: 120)]
    private string $externalEventId;

    #[ORM\Column(type: Types::STRING, length: 80)]
    private string $type;

    /**
     * Si el efecto del evento llegó a aplicarse.
     *
     * Un evento puede llegar bien firmado pero referirse a algo que no existe
     * (una suscripción borrada a mano). Se guarda igualmente y se marca como no
     * aplicado: así la pasarela deja de reintentarlo y queda rastro de que
     * llegó.
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $applied = false;

    /**
     * Por qué no se aplicó, en una frase. `null` cuando sí se aplicó.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(string $externalEventId, string $type, ?DateTimeImmutable $createdAt = null)
    {
        $externalEventId = trim($externalEventId);
        $type = trim($type);

        if ('' === $externalEventId) {
            throw new InvalidArgumentException('Un evento sin identificador no se puede deduplicar.');
        }

        if ('' === $type) {
            throw new InvalidArgumentException('Un evento sin tipo no se puede aplicar.');
        }

        $this->id = Uuid::v7();
        $this->externalEventId = $externalEventId;
        $this->type = $type;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getExternalEventId(): string
    {
        return $this->externalEventId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isApplied(): bool
    {
        return $this->applied;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function markApplied(): void
    {
        $this->applied = true;
        $this->note = null;
    }

    public function markIgnored(string $reason): void
    {
        $this->applied = false;
        $this->note = sprintf('No aplicado: %s', $reason);
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
