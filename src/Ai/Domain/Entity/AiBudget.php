<?php

declare(strict_types=1);

namespace App\Ai\Domain\Entity;

use App\Ai\Domain\Enum\AiBudgetMode;
use App\Shared\Domain\Contract\TenantAwareInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * El presupuesto de IA de una organización para un mes (Fase 14, D-55).
 *
 * ## Qué guarda y qué no
 *
 * Esta entidad guarda **la política**: cuánto puede gastar la organización
 * este mes y qué pasa cuando lo gasta todo. No guarda lo gastado.
 *
 * Lo gastado vive en `AiUsage`, que es el libro mayor real: cada llamada
 * deja una fila con su coste, sus tokens y su duración. Duplicar aquí la
 * suma gastada convertiría esta tabla en una segunda fuente de verdad, y dos
 * fuentes de verdad siempre se contradicen (D-55).
 *
 * ## Un presupuesto por mes
 *
 * El periodo es un texto `YYYY-MM`. Es la misma clave que usa la API de las
 * providers de IA para sus facturas, así que un mes de Suscripta y un mes de
 * la factura del proveedor se pueden comparar sin traducción.
 *
 * ## Por qué es una tabla y no un parámetro
 *
 * Porque el tope es una decisión **por cliente**: un freelance con veinte
 * suscripciones y una empresa con dos mil no pueden gastar lo mismo. Un
 * parámetro de configuración sería el mismo número para todos, y además
 * cambiarlo exigiría desplegar.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_budget')]
#[ORM\UniqueConstraint(name: 'uniq_ai_budget_organization_period', columns: ['organization_id', 'period'])]
class AiBudget implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(name: 'organization_id', type: 'uuid')]
    private Uuid $organizationId;

    /**
     * Periodo en formato `YYYY-MM`.
     */
    #[ORM\Column(type: Types::STRING, length: 7)]
    private string $period;

    /**
     * Tope mensual en céntimos. `0` significa «sin tope».
     */
    #[ORM\Column(name: 'limit_minor', type: Types::INTEGER)]
    private int $limitMinor;

    #[ORM\Column(type: Types::STRING, length: 10, enumType: AiBudgetMode::class)]
    private AiBudgetMode $mode;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $organizationId,
        string $period,
        int $limitMinor = 0,
        AiBudgetMode $mode = AiBudgetMode::SOFT,
        ?DateTimeImmutable $now = null,
    ) {
        $now ??= new DateTimeImmutable();

        $this->id = Uuid::v7();
        $this->organizationId = $organizationId;
        $this->period = $period;
        $this->limitMinor = max(0, $limitMinor);
        $this->mode = $mode;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Periodo `YYYY-MM` de un instante.
     */
    public static function periodOf(DateTimeImmutable $moment): string
    {
        return $moment->format('Y-m');
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

    public function getPeriod(): string
    {
        return $this->period;
    }

    /**
     * Tope mensual en céntimos (`0` = sin tope).
     */
    public function getLimitMinor(): int
    {
        return $this->limitMinor;
    }

    public function getMode(): AiBudgetMode
    {
        return $this->mode;
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
     * ¿El presupuesto no pone ningún tope?
     */
    public function isUnlimited(): bool
    {
        return $this->limitMinor <= 0;
    }

    /**
     * Cambia el tope mensual.
     */
    public function changeLimit(int $limitMinor, ?DateTimeImmutable $now = null): void
    {
        $this->limitMinor = max(0, $limitMinor);
        $this->touch($now);
    }

    /**
     * Cambia el comportamiento al agotarse el presupuesto.
     */
    public function changeMode(AiBudgetMode $mode, ?DateTimeImmutable $now = null): void
    {
        $this->mode = $mode;
        $this->touch($now);
    }

    private function touch(?DateTimeImmutable $now): void
    {
        $this->updatedAt = $now ?? new DateTimeImmutable();
    }
}
