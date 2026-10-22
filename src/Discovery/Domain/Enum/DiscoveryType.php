<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Enum;

/**
 * Qué se está proponiendo al usuario (ARCHITECTURE.md §4.6).
 *
 * La fase 3 solo generaba `NEW_SERVICE` y `PRICE_CHANGE`; el resto se define
 * ya para no tener que migrar el histórico cuando lleguen las fases de
 * detección de cambios.
 *
 * Desde la fase 15 los tipos se dividen en dos familias:
 *
 * - **Hechos**: lo que el sistema ha leído en un documento. `NEW_SERVICE`,
 *   `PRICE_CHANGE` y `PLAN_CHANGE` describen algo que la factura dice.
 * - **Inferencias**: lo que el sistema ha deducido del inventario. `DUPLICATE`,
 *   `MISSING_CHARGE` y `DORMANT` describen una sospecha, y por eso se
 *   presentan siempre como sugerencias (D-59).
 */
enum DiscoveryType: string
{
    case NEW_SERVICE = 'new_service';
    case PRICE_CHANGE = 'price_change';
    case PLAN_CHANGE = 'plan_change';
    case CANCELLATION = 'cancellation';
    case DUPLICATE = 'duplicate';
    case MISSING_CHARGE = 'missing_charge';
    case DORMANT = 'dormant';

    public function label(): string
    {
        return match ($this) {
            self::NEW_SERVICE => 'Servicio nuevo',
            self::PRICE_CHANGE => 'Cambio de precio',
            self::PLAN_CHANGE => 'Cambio de plan',
            self::CANCELLATION => 'Cancelación',
            self::DUPLICATE => 'Posible duplicado',
            self::MISSING_CHARGE => 'Cobro que no ha llegado',
            self::DORMANT => 'Servicio sin actividad',
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

    /**
     * ¿Es este tipo una inferencia y no un hecho?
     *
     * Una inferencia deducida del inventario: el sistema no ha leído en ningún
     * documento que falte un cobro, lo ha deducido del hecho de que la fecha
     * esperada pasó sin que llegara la factura. La diferencia no es
     * decorativa: una inferencia se presenta como **sugerencia** y nunca como
     * una verdad (D-59).
     */
    public function isInference(): bool
    {
        return match ($this) {
            self::DUPLICATE, self::MISSING_CHARGE, self::DORMANT => true,
            default => false,
        };
    }
}
