<?php

declare(strict_types=1);

namespace App\Integrations\Domain\Entity;

use App\Shared\Domain\Contract\TenantAwareInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Configuración de una integración externa (panel de integraciones).
 *
 * ## Qué problema resuelve
 *
 * Configurar un proveedor de IA exigía editar `.env` en el servidor y
 * **redesplegar**: la configuración vivía fuera de la aplicación, así que no
 * había forma de verla ni de cambiarla desde el panel. Con una sola
 * integración se sostiene; con cinco, no.
 *
 * Aquí la configuración pasa a ser **un dato**: se ve, se cambia y se audita
 * desde el backoffice. Añadir una integración nueva no cambia el modelo, solo
 * su clave.
 *
 * ## El secreto va cifrado, y no sale de aquí
 *
 * Las credenciales se guardan con el cifrador de credenciales —el mismo que
 * cifra la contraseña del buzón— y la entidad **no** las descifra: expone el
 * texto cifrado y quien necesite el valor real usa el servicio. Así no hay una
 * vía fácil de imprimir un token por accidente desde una plantilla.
 *
 * ## Las piezas comunes, y no un `json` de ajustes
 *
 * `label`, `baseUrl` y `credentials` son columnas porque **todas** las
 * integraciones tienen esas cosas y conviene poder listarlas y validarlas. Lo
 * que sí varía por proveedor es `settings` (un `json`): una integración puede
 * necesitar un modelo, otra un periodo, otra un umbral. Meter eso en columnas
 * obligaría a una migración por cada integración nueva, que es justo lo que se
 * quiere evitar.
 */
#[ORM\Entity]
#[ORM\Table(name: 'integration')]
#[ORM\UniqueConstraint(name: 'uniq_integration_key', columns: ['organization_id', 'integration_key'])]
#[ORM\Index(name: 'idx_integration_enabled', columns: ['is_enabled'])]
class Integration implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * Organización a la que pertenece la configuración.
     *
     * La configuración de IA es **por cliente**: una organisation puede usar
     * Groq y otra OpenAI, con claves distintas. Por eso la integración es
     * multi-tenant y no global: el filtro de Doctrine impide que una
     * organización vea (o use) las credenciales de otra.
     */
    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    /**
     * Identificador técnico de la integración (`groq`, `openai`…).
     *
     * Es la clave que usa el código para pedirla. Separada del `id` (un uuid)
     * porque esta es **estable y legible**: un uuid no dice a qué integración
     * corresponde cuando aparece en un log.
     */
    #[ORM\Column(name: 'integration_key', type: Types::STRING, length: 100)]
    private string $key;

    /**
     * Nombre para mostrar en el panel.
     *
     * No se deriva de la clave: `groq` se lee, pero «Groq» se entiende, y el
     * panel es para personas.
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $label;

    /**
     * URL base del servicio externo.
     *
     * Puede estar vacía: hay integraciones cuya dirección va en `settings` o
     * que no tienen ninguna.
     */
    #[ORM\Column(name: 'base_url', type: Types::STRING, length: 500, nullable: true)]
    private ?string $baseUrl = null;

    /**
     * Credenciales cifradas.
     *
     * Se guardan como **texto** y no como columnas separadas por credencial
     * (token, clave secreta…) porque cada proveedor usa las suyas: Groq usa una
     * API key, otro usaría un par de claves. El formato del descifrado lo
     * conoce el servicio de la integración, que es quien la consume.
     */
    #[ORM\Column(name: 'credentials_encrypted', type: Types::TEXT, nullable: true)]
    private ?string $credentialsEncrypted = null;

    /** Clave con la que se cifró, para poder rotarla después. */
    #[ORM\Column(name: 'credential_key_id', type: Types::STRING, length: 255, nullable: true)]
    private ?string $credentialKeyId = null;

    /**
     * Ajustes propios del proveedor.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $settings = [];

    /**
     * Integración activa.
     *
     * Existe para poder **desconectar** una integración sin borrar su
     * configuración: durante una incidencia, o al migrar de proveedor, se
     * quiere dejar de usarla conservando los datos para volver.
     */
    #[ORM\Column(name: 'is_enabled')]
    private bool $enabled = false;

    /**
     * Momento del último resultado correcto.
     *
     * Permite contestar «¿esto funciona?» sin salir del panel. Una integración
     * configurada pero muerta se detectaba solo por su síntoma.
     */
    #[ORM\Column(name: 'last_success_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastSuccessAt = null;

    /** Último error observado, en texto y ya recortado. */
    #[ORM\Column(name: 'last_error', type: Types::STRING, length: 500, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'last_error_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastErrorAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(Uuid $organizationId, string $key, string $label)
    {
        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->key = $key;
        $this->label = $label;
        $this->createdAt = new DateTimeImmutable();
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
        $this->touch();
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
        $this->touch();
    }

    public function getBaseUrl(): ?string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(?string $baseUrl): void
    {
        $this->baseUrl = null !== $baseUrl && '' !== trim($baseUrl) ? trim($baseUrl) : null;
        $this->touch();
    }

    public function getCredentialsEncrypted(): ?string
    {
        return $this->credentialsEncrypted;
    }

    public function getCredentialKeyId(): ?string
    {
        return $this->credentialKeyId;
    }

    public function setCredentialsEncrypted(?string $credentialsEncrypted, ?string $keyId): void
    {
        $this->credentialsEncrypted = $credentialsEncrypted;
        $this->credentialKeyId = $keyId;
        $this->touch();
    }

    public function hasCredentials(): bool
    {
        return null !== $this->credentialsEncrypted && '' !== $this->credentialsEncrypted;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function setSettings(array $settings): void
    {
        $this->settings = $settings;
        $this->touch();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->touch();
    }

    public function getLastSuccessAt(): ?DateTimeImmutable
    {
        return $this->lastSuccessAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getLastErrorAt(): ?DateTimeImmutable
    {
        return $this->lastErrorAt;
    }

    public function recordSuccess(DateTimeImmutable $at): void
    {
        $this->lastSuccessAt = $at;
        $this->lastError = null;
        $this->lastErrorAt = null;
        $this->touch();
    }

    /**
     * Registra un fallo.
     *
     * El texto se recorta porque este campo se muestra en una tabla del panel:
     * un volcado de la respuesta del proveedor (que puede traer la clave en el
     * mensaje de error) no cabe ni es seguro mostrar.
     */
    public function recordError(DateTimeImmutable $at, string $message): void
    {
        $this->lastError = mb_substr(trim($message), 0, 500);
        $this->lastErrorAt = $at;
        $this->touch();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
