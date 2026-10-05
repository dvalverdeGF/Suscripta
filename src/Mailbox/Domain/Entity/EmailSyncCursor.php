<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Entity;

use App\Shared\Domain\Contract\TenantAwareInterface;
use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function max;
use function sprintf;

use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * Punto por el que va la lectura de una carpeta (ARCHITECTURE.md §13.2).
 *
 * Sin cursor, cada sincronización vuelve a pedir la ventana entera y a
 * comparar mensaje por mensaje: funciona, pero el coste crece con el buzón en
 * lugar de con lo que llega. El cursor convierte la sincronización en
 * incremental.
 *
 * **`uidValidity` es la pieza que hace esto seguro.** Los UID de IMAP solo son
 * válidos mientras el servidor no renumere la carpeta; cuando eso pasa, el
 * servidor cambia `uidValidity` y todos los UID anteriores dejan de significar
 * lo mismo. Si no se comprueba, un `lastSeenUid` viejo haría que el sistema se
 * saltara mensajes nuevos en silencio. Por eso `observeUidValidity()` devuelve
 * si ha habido que reiniciar.
 *
 * **Backfill progresivo.** La primera sincronización está acotada por ventana y
 * por lote: un buzón de veinte mil correos no se analiza de golpe.
 * `backfillCursor` recuerda por dónde iba la lectura hacia atrás, de modo que
 * cada pasada posterior avanza un tramo más hacia el pasado sin bloquear la
 * llegada de correo nuevo.
 */
#[ORM\Entity]
#[ORM\Table(name: 'email_sync_cursor')]
#[ORM\Index(name: 'idx_email_sync_cursor_organization', columns: ['organization_id'])]
#[ORM\UniqueConstraint(name: 'uniq_email_sync_cursor_account_folder', columns: ['email_account_id', 'folder'])]
class EmailSyncCursor implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    #[ORM\Column(name: 'email_account_id', type: 'uuid')]
    private Uuid $emailAccountId;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $folder;

    /**
     * Valor de `UIDVALIDITY` de la carpeta la última vez que se leyó. Cero
     * significa «todavía no lo sabemos».
     */
    #[ORM\Column(name: 'uid_validity', type: Types::INTEGER)]
    private int $uidValidity = 0;

    /**
     * UID más alto ya visto. La siguiente lectura pide los UID mayores que
     * este.
     */
    #[ORM\Column(name: 'last_seen_uid', type: Types::INTEGER)]
    private int $lastSeenUid = 0;

    /**
     * UID más bajo ya visto. La lectura hacia atrás continúa por debajo de
     * este. `null` significa que no queda nada por recuperar.
     */
    #[ORM\Column(name: 'backfill_cursor', type: Types::INTEGER, nullable: true)]
    private ?int $backfillCursor = null;

    #[ORM\Column(name: 'last_sync_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastSyncAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $organizationId,
        Uuid $emailAccountId,
        string $folder,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $folder = trim($folder);

        if ('' === $folder) {
            throw new InvalidArgumentException('La carpeta del cursor no puede estar vacía.');
        }

        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->emailAccountId = $emailAccountId;
        $this->folder = $folder;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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

    public function getEmailAccountId(): Uuid
    {
        return $this->emailAccountId;
    }

    public function getFolder(): string
    {
        return $this->folder;
    }

    public function getUidValidity(): int
    {
        return $this->uidValidity;
    }

    public function getLastSeenUid(): int
    {
        return $this->lastSeenUid;
    }

    public function getBackfillCursor(): ?int
    {
        return $this->backfillCursor;
    }

    public function getLastSyncAt(): ?DateTimeImmutable
    {
        return $this->lastSyncAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * ¿Es la primera lectura de esta carpeta?
     */
    public function isInitial(): bool
    {
        return 0 === $this->lastSeenUid;
    }

    /**
     * ¿Queda histórico por recuperar hacia atrás?
     */
    public function hasBackfillPending(): bool
    {
        return null !== $this->backfillCursor && $this->backfillCursor > 1;
    }

    /**
     * Registra el `UIDVALIDITY` que acaba de reportar el servidor.
     *
     * Devuelve `true` si ha cambiado, es decir, si el servidor ha renumerado la
     * carpeta y todo lo que sabíamos sobre sus UID ya no vale. En ese caso el
     * cursor se reinicia: es preferible releer metadatos (barato y deduplicado)
     * que saltarse mensajes en silencio.
     */
    public function observeUidValidity(int $uidValidity): bool
    {
        if ($uidValidity <= 0) {
            throw new InvalidArgumentException('El UIDVALIDITY de la carpeta debe ser un entero positivo.');
        }

        if ($this->uidValidity === $uidValidity) {
            return false;
        }

        $this->uidValidity = $uidValidity;
        $this->lastSeenUid = 0;
        $this->backfillCursor = null;
        $this->touch();

        return true;
    }

    /**
     * Avanza el cursor hasta el UID más alto visto.
     *
     * Nunca retrocede: una lectura hacia atrás (backfill) no debe hacer que la
     * siguiente lectura incremental vuelva a pedir lo que ya está procesado.
     */
    public function advanceTo(int $uid): void
    {
        if ($uid <= 0) {
            throw new InvalidArgumentException('El UID del cursor debe ser un entero positivo.');
        }

        if ($uid <= $this->lastSeenUid) {
            return;
        }

        $this->lastSeenUid = $uid;
        $this->touch();
    }

    /**
     * Fija por dónde continúa la lectura hacia atrás.
     *
     * `null` marca el final del histórico: no queda nada más antiguo que
     * recuperar.
     */
    public function setBackfillCursor(?int $uid): void
    {
        if (null !== $uid && $uid <= 0) {
            throw new InvalidArgumentException('El cursor de backfill debe ser un entero positivo.');
        }

        $this->backfillCursor = $uid;
        $this->touch();
    }

    /**
     * Marca el final del histórico: ya se ha leído todo lo que había.
     */
    public function completeBackfill(): void
    {
        $this->backfillCursor = null;
        $this->touch();
    }

    public function recordSync(DateTimeImmutable $at): void
    {
        $this->lastSyncAt = $at;
        $this->touch();
    }

    /**
     * Reinicia el cursor conservando el `UIDVALIDITY` conocido.
     */
    public function reset(): void
    {
        $this->lastSeenUid = 0;
        $this->backfillCursor = null;
        $this->touch();
    }

    /**
     * Descripción legible del estado, para la interfaz de sincronización.
     */
    public function describe(): string
    {
        if ($this->isInitial()) {
            return 'Sin sincronizar todavía.';
        }

        return sprintf(
            'Último mensaje leído: UID %d%s.',
            $this->lastSeenUid,
            $this->hasBackfillPending() ? sprintf(' (recuperando histórico hasta el UID %d)', (int) $this->backfillCursor) : '',
        );
    }

    /**
     * El UID más bajo que hay que pedir en la siguiente lectura hacia atrás.
     */
    public function backfillFrom(): int
    {
        return max(1, $this->backfillCursor ?? 1);
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
