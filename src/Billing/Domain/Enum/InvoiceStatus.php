<?php

declare(strict_types=1);

namespace App\Billing\Domain\Enum;

use function strtolower;
use function trim;

/**
 * Estado de una factura de venta (Fase 24).
 *
 * Es el estado **del cobro**, no el de la suscripción: una factura `open` puede
 * convivir con una suscripción `past_due` (se emitió y no se ha cobrado), y una
 * `void` con una suscripción activa (se anuló a mano). Mezclarlos haría que
 * anular una factura cancelara el servicio, que es exactamente lo que no se
 * quiere.
 *
 * Los valores son los de la pasarela para que no haya que traducir en la
 * frontera; lo que sí es nuestro es el significado que se les da en el panel.
 */
enum InvoiceStatus: string
{
    case DRAFT = 'draft';
    case OPEN = 'open';
    case PAID = 'paid';
    case VOID = 'void';
    case UNCOLLECTIBLE = 'uncollectible';

    public static function tryFromGateway(?string $value): ?self
    {
        if (null === $value) {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Borrador',
            self::OPEN => 'Pendiente',
            self::PAID => 'Cobrada',
            self::VOID => 'Anulada',
            self::UNCOLLECTIBLE => 'Incobrable',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::DRAFT => 'secondary',
            self::OPEN => 'warning',
            self::PAID => 'success',
            self::VOID => 'secondary',
            self::UNCOLLECTIBLE => 'danger',
        };
    }

    /**
     * Si el dinero ha entrado. Es lo que decide si la factura cuenta en los
     * ingresos del panel: una `open` es una deuda, no un ingreso.
     */
    public function isPaid(): bool
    {
        return self::PAID === $this;
    }
}
