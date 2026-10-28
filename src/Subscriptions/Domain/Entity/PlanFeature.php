<?php

declare(strict_types=1);

namespace App\Subscriptions\Domain\Entity;

use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function mb_strlen;
use function sprintf;

use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * Característica de un plan, tal y como se enseña en la página de precios
 * (Fase 22).
 *
 * ## Por qué es texto y no una capacidad del código
 *
 * `Capability` (`services`, `documents`, `ai`…) es lo que la aplicación
 * **aplica**: un límite que se comprueba. Una característica es lo que se
 * **cuenta**: «Copias de seguridad diarias», «Soporte por correo». La primera
 * no puede existir sin código detrás; la segunda es una frase que el operador
 * escribe y cambia cuando quiere.
 *
 * Por eso `included` es un booleano y no un número: la lista de una tarjeta de
 * precios enseña también lo que **no** entra («Inteligencia artificial: no»),
 * porque una tabla donde solo aparecen ventajas no ayuda a decidir.
 *
 * ## Por qué no es un catálogo global con una tabla de unión
 *
 * Sería lo correcto si las mismas características se reutilizaran entre
 * planes y hubiera que compararlas. Aquí cada plan lleva su lista, en su
 * orden, y comparar dos planes es leer dos listas. Una tabla de unión añadiría
 * una entidad y un formulario para ahorrar una fila repetida.
 */
#[ORM\Entity]
#[ORM\Table(name: 'plan_feature')]
#[ORM\Index(name: 'idx_plan_feature_plan_position', columns: ['plan_id', 'position'])]
class PlanFeature
{
    public const MAX_LABEL_LENGTH = 120;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(name: 'plan_id', nullable: false, onDelete: 'CASCADE')]
    private Plan $plan;

    #[ORM\Column(type: Types::STRING, length: 120)]
    private string $label;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $included = true;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(Plan $plan, string $label, bool $included = true, int $position = 0)
    {
        $this->id = Uuid::v7();
        $this->plan = $plan;
        $this->label = self::assertLabel($label);
        $this->included = $included;
        $this->position = $position;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPlan(): Plan
    {
        return $this->plan;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isIncluded(): bool
    {
        return $this->included;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function rename(string $label): void
    {
        $this->label = self::assertLabel($label);
    }

    public function setIncluded(bool $included): void
    {
        $this->included = $included;
    }

    public function moveTo(int $position): void
    {
        if ($position < 0) {
            throw new InvalidArgumentException('El orden de una característica no puede ser negativo.');
        }

        $this->position = $position;
    }

    private static function assertLabel(string $label): string
    {
        $label = trim($label);

        if ('' === $label) {
            throw new InvalidArgumentException('La característica necesita un texto.');
        }

        if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new InvalidArgumentException(sprintf('La característica no puede pasar de %d caracteres.', self::MAX_LABEL_LENGTH));
        }

        return $label;
    }
}
