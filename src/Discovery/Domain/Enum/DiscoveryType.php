<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Enum;

/**
 * Qué se está proponiendo al usuario (ARCHITECTURE.md §4.6).
 *
 * En la Fase 3 solo se generan `NEW_SERVICE` y `PRICE_CHANGE`; el resto se
 * define ya para no tener que migrar el histórico cuando lleguen las fases de
 * detección de cambios.
 */
enum DiscoveryType: string
{
    case NEW_SERVICE = 'new_service';
    case PRICE_CHANGE = 'price_change';
    case PLAN_CHANGE = 'plan_change';
    case CANCELLATION = 'cancellation';
    case DUPLICATE = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::NEW_SERVICE => 'Servicio nuevo',
            self::PRICE_CHANGE => 'Cambio de precio',
            self::PLAN_CHANGE => 'Cambio de plan',
            self::CANCELLATION => 'Cancelación',
            self::DUPLICATE => 'Posible duplicado',
        };
    }

    /**
     * Las propuestas que crean un servicio nuevo necesitan que el usuario
     * confirme; las que modifican uno existente también, pero el texto de la
     * interfaz es distinto.
     */
    public function createsService(): bool
    {
        return self::NEW_SERVICE === $this;
    }
}
