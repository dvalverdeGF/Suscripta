<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Enum;

use function in_array;

/**
 * Motivos por los que el sistema avisa.
 *
 * Todos son **deterministas**: se derivan de fechas e importes que ya están en
 * la base de datos. Ninguno depende de IA ni de una predicción, así que el
 * usuario puede comprobar por qué ha recibido el aviso (PRODUCT.md §6.4).
 */
enum AlertType: string
{
    case UPCOMING_CHARGE = 'upcoming_charge';
    case UPCOMING_RENEWAL = 'upcoming_renewal';
    case ANNUAL_RENEWAL = 'annual_renewal';
    case NOTICE_DEADLINE = 'notice_deadline';
    case COMMITMENT_ENDING = 'commitment_ending';
    case PRICE_INCREASE = 'price_increase';
    case DISCOVERY_PENDING = 'discovery_pending';

    public function label(): string
    {
        return match ($this) {
            self::UPCOMING_CHARGE => 'Cobro próximo',
            self::UPCOMING_RENEWAL => 'Renovación próxima',
            self::ANNUAL_RENEWAL => 'Renovación anual próxima',
            self::NOTICE_DEADLINE => 'Se acaba el plazo para avisar',
            self::COMMITMENT_ENDING => 'Se acaba el compromiso',
            self::PRICE_INCREASE => 'Ha subido de precio',
            self::DISCOVERY_PENDING => 'Hay algo que revisar',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::UPCOMING_CHARGE => 'Te van a cobrar en los próximos días.',
            self::UPCOMING_RENEWAL => 'Un servicio se renueva pronto.',
            self::ANNUAL_RENEWAL => 'Un servicio anual se renueva pronto. Suele ser el momento de decidir si sigues.',
            self::NOTICE_DEADLINE => 'Se acerca la fecha límite para avisar de que no quieres renovar.',
            self::COMMITMENT_ENDING => 'Termina el periodo de compromiso y podrás cancelar sin coste.',
            self::PRICE_INCREASE => 'Un servicio ha subido de precio.',
            self::DISCOVERY_PENDING => 'Hemos encontrado algo en tu correo y esperamos tu confirmación.',
        };
    }

    /**
     * Los avisos que dependen de una fecha futura y por tanto se pueden
     * anticipar. El resto son reacciones a algo que ya ha pasado.
     */
    public function isAnticipated(): bool
    {
        return in_array($this, [
            self::UPCOMING_CHARGE,
            self::UPCOMING_RENEWAL,
            self::ANNUAL_RENEWAL,
            self::NOTICE_DEADLINE,
            self::COMMITMENT_ENDING,
        ], true);
    }

    /**
     * Los avisos que el usuario puede silenciar sin perder información crítica.
     *
     * Un cobro próximo no se puede silenciar: es la razón de ser del producto.
     */
    public function isSilenceable(): bool
    {
        return self::UPCOMING_CHARGE !== $this;
    }
}
