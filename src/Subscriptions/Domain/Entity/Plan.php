<?php

declare(strict_types=1);

namespace App\Subscriptions\Domain\Entity;

use App\Identity\Domain\Enum\OrganizationPlan;
use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function mb_strlen;
use function sprintf;

use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * Plan del catálogo comercial (Fase 22).
 *
 * ## Qué añade sobre `OrganizationPlan`
 *
 * `OrganizationPlan` dice **qué planes existen y qué límites tienen**, y eso
 * vive en el código y en `config/packages/entitlements.yaml` (D-64). Este
 * `Plan` dice **cómo se vende**: nombre comercial, gancho, descripción, orden
 * en la página de precios, si está publicado y cuánto cuesta.
 *
 * El código del plan es un `OrganizationPlan`, no un texto libre: un plan del
 * catálogo que no exista en el enum sería un plan que nadie puede tener, y un
 * plan del enum sin fila en el catálogo sería un plan sin precio. Al atar los
 * dos, el catálogo no puede inventar productos que la aplicación no sabe
 * aplicar.
 *
 * ## Los límites no se copian aquí
 *
 * Es la tentación evidente: añadir `services`, `documents`, `ai` a esta
 * entidad y pintarlos en la página de precios. Sería una segunda fuente de la
 * verdad que se desincroniza el día que alguien cambie el YAML. La pantalla
 * del catálogo lee los límites de `EntitlementSettings` y los enseña en modo
 * lectura.
 *
 * ## Por qué el catálogo es global y no por organización
 *
 * Es la oferta de Suscripta, no un dato del cliente. Por eso la entidad no es
 * `TenantAwareInterface` y el panel exige `ROLE_OPERATOR` (D-80): un inquilino
 * no puede reescribir el precio que ven todos los demás.
 */
#[ORM\Entity]
#[ORM\Table(name: 'plan')]
#[ORM\UniqueConstraint(name: 'uniq_plan_code', columns: ['code'])]
#[ORM\Index(name: 'idx_plan_position', columns: ['position'])]
class Plan
{
    /**
     * Longitud máxima del nombre comercial. Cabe en una tarjeta de precios y
     * en una columna de tabla sin recortes.
     */
    public const MAX_NAME_LENGTH = 80;

    /** Longitud máxima del gancho de una línea. */
    public const MAX_TAGLINE_LENGTH = 160;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * El plan de la aplicación al que corresponde. Es la unión con los
     * límites: `OrganizationPlan::FREE` aquí es el plan «Gratis» de la web.
     */
    #[ORM\Column(type: Types::STRING, length: 20, enumType: OrganizationPlan::class)]
    private OrganizationPlan $code;

    #[ORM\Column(type: Types::STRING, length: 80)]
    private string $name;

    /**
     * Una línea que resume el plan («Para probar sin compromiso»). Es lo que
     * se lee en la tarjeta de precios antes que la lista de características.
     */
    #[ORM\Column(type: Types::STRING, length: 160, nullable: true)]
    private ?string $tagline = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Si está publicado. Un plan desactivado sigue existiendo —las
     * suscripciones que lo usan no se rompen— pero no se ofrece.
     */
    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN)]
    private bool $isActive = true;

    /**
     * Si es el plan recomendado en la página de precios. Solo puede haber uno
     * destacado, y eso lo decide el operador, no una regla automática.
     */
    #[ORM\Column(name: 'is_highlighted', type: Types::BOOLEAN)]
    private bool $isHighlighted = false;

    /**
     * Orden de presentación. Se guarda como número y no como fecha porque el
     * orden es una decisión editorial: el plan barato primero, el caro
     * después, sin que importe cuándo se dieron de alta.
     */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $position = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(OrganizationPlan $code, string $name, int $position = 0)
    {
        $this->id = Uuid::v7();
        $this->code = $code;
        $this->name = self::assertName($name);
        $this->position = $position;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCode(): OrganizationPlan
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTagline(): ?string
    {
        return $this->tagline;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function isHighlighted(): bool
    {
        return $this->isHighlighted;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function rename(string $name): void
    {
        $this->name = self::assertName($name);
        $this->touch();
    }

    public function describe(?string $tagline, ?string $description): void
    {
        $tagline = null === $tagline ? null : trim($tagline);
        $tagline = '' === $tagline ? null : $tagline;

        if (null !== $tagline && mb_strlen($tagline) > self::MAX_TAGLINE_LENGTH) {
            throw new InvalidArgumentException(sprintf('El gancho del plan no puede pasar de %d caracteres.', self::MAX_TAGLINE_LENGTH));
        }

        $description = null === $description ? null : trim($description);
        $description = '' === $description ? null : $description;

        $this->tagline = $tagline;
        $this->description = $description;
        $this->touch();
    }

    /**
     * Publica o retira el plan. Retirarlo no borra nada: quien lo tenga
     * contratado lo conserva, simplemente deja de ofrecerse.
     */
    public function setActive(bool $active): void
    {
        $this->isActive = $active;
        $this->touch();
    }

    public function setHighlighted(bool $highlighted): void
    {
        $this->isHighlighted = $highlighted;
        $this->touch();
    }

    public function moveTo(int $position): void
    {
        if ($position < 0) {
            throw new InvalidArgumentException('El orden de presentación no puede ser negativo.');
        }

        $this->position = $position;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    private static function assertName(string $name): string
    {
        $name = trim($name);

        if ('' === $name) {
            throw new InvalidArgumentException('El plan necesita un nombre.');
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf('El nombre del plan no puede pasar de %d caracteres.', self::MAX_NAME_LENGTH));
        }

        return $name;
    }
}
