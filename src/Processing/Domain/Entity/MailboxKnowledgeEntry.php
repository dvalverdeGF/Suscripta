<?php

declare(strict_types=1);

namespace App\Processing\Domain\Entity;

use App\Documents\Domain\Enum\DocumentType;
use App\Processing\Domain\Enum\MailboxKnowledgeSource;
use App\Processing\Domain\Enum\MailboxKnowledgeType;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\BillingPeriod;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function mb_strtolower;

use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * Lo que el sistema ha aprendido de **un buzón concreto**
 * (ARCHITECTURE.md §13.12, D-33).
 *
 * Una fila es una afirmación comprobable: «este remitente es este proveedor»,
 * «los asuntos con esta plantilla son facturas», «este remitente no me
 * interesa». Todo es editable y visible por el usuario, y nada se comparte
 * entre buzones: dos cuentas del mismo tenant pueden tener realidad distinta
 * (una recibe las facturas de OVH en francés y otra en español).
 *
 * La tabla no tiene relación con `provider_identity` a propósito: el
 * conocimiento global y el conocimiento del buzón son cosas diferentes, y
 * mezclarlos produciría contaminación cruzada entre usuarios.
 */
#[ORM\Entity]
#[ORM\Table(name: 'mailbox_knowledge_entry')]
#[ORM\UniqueConstraint(
    name: 'uniq_mailbox_knowledge_account_type_value',
    columns: ['email_account_id', 'type', 'value'],
)]
#[ORM\Index(name: 'idx_mailbox_knowledge_account', columns: ['email_account_id'])]
#[ORM\Index(name: 'idx_mailbox_knowledge_account_type', columns: ['email_account_id', 'type'])]
class MailboxKnowledgeEntry
{
    /**
     * Confianza mínima para que un remitente ignorado se salte el mensaje.
     *
     * Un solo descarte no significa que el remitente no sirva: el usuario
     * puede descartar una factura concreta y seguir queriendo las demás. Dos
     * descartes del mismo remitente ya son una señal, y el usuario siempre
     * puede ajustarlo a mano desde la pantalla de conocimiento.
     */
    public const int IGNORE_CONFIDENCE_THRESHOLD = 60;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'email_account_id', type: 'uuid')]
    private Uuid $emailAccountId;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: MailboxKnowledgeType::class)]
    private MailboxKnowledgeType $type;

    /**
     * El valor según el tipo:
     *
     * - `SENDER_MAPPING` / `PROVIDER_HINT` / `IGNORED_SENDER`: la dirección de
     *   correo o el dominio, en minúsculas.
     * - `SUBJECT_PATTERN` / `DOCUMENT_PATTERN`: una expresión regular.
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $value;

    #[ORM\Column(name: 'provider_id', type: 'uuid', nullable: true)]
    private ?Uuid $providerId = null;

    #[ORM\Column(name: 'document_type', type: Types::STRING, length: 20, enumType: DocumentType::class, nullable: true)]
    private ?DocumentType $documentType = null;

    #[ORM\Column(name: 'billing_period', type: Types::STRING, length: 20, enumType: BillingPeriod::class, nullable: true)]
    private ?BillingPeriod $billingPeriod = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $confidence = 60;

    #[ORM\Column(name: 'hit_count', type: Types::INTEGER)]
    private int $hitCount = 0;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'promoted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $promotedAt = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: MailboxKnowledgeSource::class)]
    private MailboxKnowledgeSource $source;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $emailAccountId,
        Uuid $organizationId,
        MailboxKnowledgeType $type,
        string $value,
        ?Uuid $providerId = null,
        ?DocumentType $documentType = null,
        ?BillingPeriod $billingPeriod = null,
        MailboxKnowledgeSource $source = MailboxKnowledgeSource::OBSERVED,
        ?int $confidence = null,
        ?DateTimeImmutable $now = null,
    ) {
        $now ??= new DateTimeImmutable();

        $this->id = Uuid::v7();
        $this->emailAccountId = $emailAccountId;
        $this->organizationId = $organizationId;
        $this->type = $type;
        $this->value = $this->normalizeValue($type, $value);
        $this->providerId = $providerId;
        $this->documentType = $documentType;
        $this->billingPeriod = $billingPeriod;
        $this->source = $source;
        $this->confidence = $confidence ?? $source->defaultConfidence();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmailAccountId(): Uuid
    {
        return $this->emailAccountId;
    }

    public function getOrganizationId(): Uuid
    {
        return $this->organizationId;
    }

    public function getType(): MailboxKnowledgeType
    {
        return $this->type;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getProviderId(): ?Uuid
    {
        return $this->providerId;
    }

    public function getDocumentType(): ?DocumentType
    {
        return $this->documentType;
    }

    public function getBillingPeriod(): ?BillingPeriod
    {
        return $this->billingPeriod;
    }

    public function getConfidence(): int
    {
        return $this->confidence;
    }

    public function getHitCount(): int
    {
        return $this->hitCount;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getPromotedAt(): ?DateTimeImmutable
    {
        return $this->promotedAt;
    }

    public function getSource(): MailboxKnowledgeSource
    {
        return $this->source;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isPromoted(): bool
    {
        return null !== $this->promotedAt;
    }

    /**
     * ¿Este conocimiento descarta el mensaje sin mirarlo siquiera?
     */
    public function excludes(): bool
    {
        return $this->type->isExclusion() && $this->confidence >= self::IGNORE_CONFIDENCE_THRESHOLD;
    }

    /**
     * Anota que el conocimiento se ha usado. Es la señal que permite podar: lo
     * que nunca se usa sobrará.
     */
    public function recordHit(DateTimeImmutable $at): void
    {
        ++$this->hitCount;
        $this->lastUsedAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * La confianza nunca baja sola: para eso está la corrección explícita del
     * usuario (que borra la entrada).
     */
    public function reinforce(int $confidence, DateTimeImmutable $at): void
    {
        $this->confidence = max($this->confidence, max(0, min(100, $confidence)));
        $this->updatedAt = $at;
    }

    /**
     * Rellena lo que todavía no sabemos de esta entrada.
     *
     * Se usa al aprender: una primera observación puede saber el proveedor
     * pero no la periodicidad, y una segunda puede completar el hueco.
     *
     * Cuando el dato viene del usuario (`$authoritative`), **reemplaza** al
     * observado: si el sistema creyó que la cuota era mensual y el usuario
     * confirma que es anual, gana el usuario. Un dato deducido, en cambio,
     * solo rellena huecos: no pisa lo que ya sabemos.
     */
    public function learn(
        ?Uuid $providerId,
        ?DocumentType $documentType,
        ?BillingPeriod $billingPeriod,
        int $confidence,
        bool $authoritative,
        DateTimeImmutable $at,
    ): void {
        if (null !== $providerId && (null === $this->providerId || $authoritative)) {
            $this->providerId = $providerId;
        }

        if (null !== $documentType && (null === $this->documentType || $authoritative)) {
            $this->documentType = $documentType;
        }

        if (null !== $billingPeriod && (null === $this->billingPeriod || $authoritative)) {
            $this->billingPeriod = $billingPeriod;
        }

        if ($authoritative) {
            $this->source = MailboxKnowledgeSource::USER;
        }

        $this->reinforce($confidence, $at);
    }

    /**
     * El usuario edita la entrada desde la pantalla de conocimiento.
     */
    public function edit(
        string $value,
        ?Uuid $providerId,
        ?DocumentType $documentType,
        ?BillingPeriod $billingPeriod,
        int $confidence,
        DateTimeImmutable $at,
    ): void {
        $this->value = $this->normalizeValue($this->type, $value);
        $this->providerId = $providerId;
        $this->documentType = $documentType;
        $this->billingPeriod = $billingPeriod;
        $this->confidence = max(0, min(100, $confidence));
        $this->updatedAt = $at;
    }

    /**
     * Anota que este conocimiento se promovió a catálogo global. La
     * promoción siempre es explícita (D-33).
     */
    public function markPromoted(DateTimeImmutable $at): void
    {
        $this->promotedAt = $at;
        $this->updatedAt = $at;
    }

    /**
     * Los patrones conservan las mayúsculas; el resto se normaliza para que la
     * búsqueda no falle por una mayúscula.
     */
    private function normalizeValue(MailboxKnowledgeType $type, string $value): string
    {
        $value = trim($value);

        if ('' === $value) {
            throw new InvalidArgumentException('El valor del conocimiento no puede estar vacío.');
        }

        return $type->isPattern() ? $value : mb_strtolower($value);
    }
}
