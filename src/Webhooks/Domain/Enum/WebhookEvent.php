<?php

declare(strict_types=1);

namespace App\Webhooks\Domain\Enum;

/**
 * Hechos que Suscripta puede anunciar a un suscriptor externo (Fase 20).
 *
 * La lista es **corta a propósito**. Un webhook es un contrato con alguien de
 * fuera: cada evento que se publica hay que mantenerlo, versionarlo y no
 * romperlo. Solo entran los hechos que (a) ya tienen un momento claro en el
 * dominio y (b) un integrador no puede averiguar por su cuenta consultando la
 * API. Si se puede saber con un `GET`, no hace falta un webhook.
 *
 * El nombre viaja en el campo `event` del cuerpo y en la cabecera
 * `X-Suscripta-Event`, con el formato `recurso.acción` en pasado.
 */
enum WebhookEvent: string
{
    /** Se ha dado de alta un servicio en el inventario. */
    case SERVICE_CREATED = 'service.created';

    /** El sistema ha propuesto un descubrimiento que espera revisión. */
    case PROPOSAL_CREATED = 'proposal.created';

    /** El usuario ha confirmado una propuesta y ha pasado a ser un servicio. */
    case PROPOSAL_CONFIRMED = 'proposal.confirmed';

    /** Se ha detectado un cobro nuevo (precio registrado) en un servicio. */
    case CHARGE_DETECTED = 'charge.detected';

    /** La organización ha agotado su presupuesto de IA del periodo. */
    case AI_BUDGET_EXHAUSTED = 'ai.budget_exhausted';

    /**
     * Nombre legible, para el panel.
     */
    public function label(): string
    {
        return match ($this) {
            self::SERVICE_CREATED => 'Servicio dado de alta',
            self::PROPOSAL_CREATED => 'Propuesta creada',
            self::PROPOSAL_CONFIRMED => 'Propuesta confirmada',
            self::CHARGE_DETECTED => 'Cobro detectado',
            self::AI_BUDGET_EXHAUSTED => 'Presupuesto de IA agotado',
        };
    }

    /**
     * Todos los eventos, en el orden en que se muestran en el formulario.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
