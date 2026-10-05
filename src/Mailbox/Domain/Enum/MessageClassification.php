<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Enum;

/**
 * Qué es el documento que hemos encontrado (ARCHITECTURE.md §4.5).
 *
 * `UNKNOWN` no es un error: es la respuesta honesta cuando no hay confianza
 * suficiente, y es lo que lleva el mensaje a `REQUIRES_REVIEW` en lugar de a un
 * descubrimiento inventado.
 */
enum MessageClassification: string
{
    case INVOICE = 'invoice';
    case RECEIPT = 'receipt';
    case PAYMENT_CONFIRMATION = 'payment_confirmation';
    case RENEWAL_NOTICE = 'renewal_notice';
    case PRICE_CHANGE = 'price_change';
    case PLAN_CHANGE = 'plan_change';
    case EXPIRATION_NOTICE = 'expiration_notice';
    case OTHER = 'other';
    case UNKNOWN = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::INVOICE => 'Factura',
            self::RECEIPT => 'Recibo',
            self::PAYMENT_CONFIRMATION => 'Confirmación de pago',
            self::RENEWAL_NOTICE => 'Aviso de renovación',
            self::PRICE_CHANGE => 'Cambio de precio',
            self::PLAN_CHANGE => 'Cambio de plan',
            self::EXPIRATION_NOTICE => 'Aviso de caducidad',
            self::OTHER => 'Otro',
            self::UNKNOWN => 'Sin determinar',
        };
    }

    /**
     * Los tipos que justifican proponer un servicio al usuario.
     */
    public function isBillable(): bool
    {
        return match ($this) {
            self::INVOICE, self::RECEIPT, self::PAYMENT_CONFIRMATION, self::RENEWAL_NOTICE => true,
            default => false,
        };
    }
}
