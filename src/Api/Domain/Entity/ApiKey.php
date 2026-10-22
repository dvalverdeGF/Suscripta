<?php

declare(strict_types=1);

namespace App\Api\Domain\Entity;

use App\Api\Domain\Enum\ApiScope;
use App\Shared\Domain\Contract\TenantAwareInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Credencial de acceso a la API pública (Fase 18).
 *
 * ## Lo que se guarda y lo que no
 *
 * De la clave **solo** se guarda su huella. El texto en claro se le da al
 * operador una vez, en el momento de crearla, y no vuelve a existir en el
 * sistema: ni en la base de datos, ni en los logs, ni en un mensaje de
 * «te la_HAS_olvidado» (que no existe: si se pierde, se crea otra y se
 * revoca esta).
 *
 * La huella es un HMAC con un secreto del servidor, no un hash simple: con un
 * hash simple, quien tenga acceso a la base de datos podría comprobar
 * candidatos; con HMAC, el secreto no está en la base de datos, así que el
 * atacante tiene que extraerlo de la aplicación. Y no es `bcrypt` a propósito:
 * la clave tiene 256 bits de entropía, así que no hay diccionario que atacar y
 * la comprobación tiene que ser **rápida** (cada petición de API verifica una
 * clave).
 *
 * ## El prefijo
 *
 * Las primeras posiciones de la clave se guardan **en claro** para poder
 * reconocerla en el panel y en los logs sin guardarla entera. Es el mismo
 * truco que usan las claves de GitHub o Stripe: no sirve para autenticarse,
 * pero identifica.
 *
 * ## Aislamiento
 *
 * Es `TenantAwareInterface`: el filtro de Doctrine impide que una organización
 * vea las claves de otra, y la autenticación solo puede dar acceso a la
 * organización a la que pertenece la clave.
 */
#[ORM\Entity]
#[ORM\Table(name: 'api_key')]
#[ORM\UniqueConstraint(name: 'uniq_api_key_secret_hash', columns: ['secret_hash'])]
#[ORM\Index(name: 'idx_api_key_organization', columns: ['organization_id'])]
class ApiKey implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * Organización a la que da acceso.
     *
     * La clave **no** da acceso a un usuario: da acceso a una organización.
     * Es lo que hace que un integrador no dependa de que una persona siga
     * en la empresa, y lo que hace que el aislamiento por inquilino se pueda
     * comprobar en un solo sitio.
     */
    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    /** Nombre que le da el operador: «Integración con el ERP», «Power BI»… */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $name;

    /**
     * Prefijo visible de la clave (`sk_a1B2c3D4…`).
     *
     * Solo informativo: no sirve para autenticarse.
     */
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $prefix;

    /** HMAC-SHA-256 de la clave, en hexadecimal. */
    #[ORM\Column(name: 'secret_hash', type: Types::STRING, length: 64)]
    private string $secretHash;

    /**
     * Ámbitos de la clave.
     *
     * Se guardan como texto (`["read"]`) y no como enumeración porque el
     * filtro de Doctrine no sabe mapear enumeraciones dentro de un `json`.
     * La conversión la hace la entidad, que es la única que escribe aquí.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $scopes = [];

    /** Caducidad. `null` significa «no caduca». */
    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $expiresAt = null;

    /** Momento de la revocación. `null` significa «sigue viva». */
    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    /** Último uso, para saber si una clave sigue viva o está olvidada. */
    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastUsedAt = null;

    /** Usuario que la creó, para poder saber quién dio acceso y a qué. */
    #[ORM\Column(name: 'created_by_user_id', type: 'uuid', nullable: true)]
    private ?Uuid $createdByUserId = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /**
     * @param list<ApiScope> $scopes
     */
    public function __construct(
        Uuid $organizationId,
        string $name,
        string $prefix,
        string $secretHash,
        array $scopes,
        ?DateTimeImmutable $expiresAt = null,
        ?Uuid $createdByUserId = null,
    ) {
        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->name = $name;
        $this->prefix = $prefix;
        $this->secretHash = $secretHash;
        $this->setScopes($scopes);
        $this->expiresAt = $expiresAt;
        $this->createdByUserId = $createdByUserId;
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

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getSecretHash(): string
    {
        return $this->secretHash;
    }

    /**
     * @return list<ApiScope>
     */
    public function getScopes(): array
    {
        $scopes = [];

        foreach ($this->scopes as $scope) {
            $scopes[] = ApiScope::from($scope);
        }

        return $scopes;
    }

    /**
     * @param list<ApiScope> $scopes
     */
    public function setScopes(array $scopes): void
    {
        $this->scopes = array_map(static fn (ApiScope $scope): string => $scope->value, $scopes);
    }

    /**
     * ¿La clave puede hacer esto?
     *
     * Un ámbito guardado cubre a los que él mismo cubre: como `write` cubre
     * `read`, una clave de escritura también puede leer.
     */
    public function allows(ApiScope $scope): bool
    {
        foreach ($this->getScopes() as $granted) {
            if ($granted->covers($scope)) {
                return true;
            }
        }

        return false;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getCreatedByUserId(): ?Uuid
    {
        return $this->createdByUserId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return null !== $this->expiresAt && $this->expiresAt <= $now;
    }

    /**
     * ¿La clave sirve ahora mismo?
     *
     * Una clave revocada **no vuelve a revivir** aunque su fecha de caducidad
     * pase: revocar es la decisión del operador y manda sobre todo lo demás.
     */
    public function isUsable(DateTimeImmutable $now): bool
    {
        return !$this->isRevoked() && !$this->isExpired($now);
    }

    public function revoke(DateTimeImmutable $at): void
    {
        if (null === $this->revokedAt) {
            $this->revokedAt = $at;
        }
    }

    public function recordUse(DateTimeImmutable $at): void
    {
        $this->lastUsedAt = $at;
    }
}
