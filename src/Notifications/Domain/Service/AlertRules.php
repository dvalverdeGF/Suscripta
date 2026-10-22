<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Service;

use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Enum\NotificationChannel;

use function array_merge;
use function max;

/**
 * Los umbrales que deciden cuándo se avisa y con cuánta urgencia.
 *
 * Están todos aquí, en un solo sitio y como constantes, porque son decisiones
 * de producto que hay que poder discutir y ajustar sin leer el generador
 * entero. Ninguno depende de IA: son días y porcentajes.
 */
final class AlertRules
{
    /** Días de antelación con los que se avisa de un cobro. */
    public const CHARGE_LEAD_DAYS = 7;

    /** Días de antelación con los que se avisa de una renovación. */
    public const RENEWAL_LEAD_DAYS = 30;

    /**
     * Días de antelación con los que se avisa de una renovación anual.
     *
     * Más margen que una mensual porque una renovación anual es una decisión
     * grande y a menudo hay que avisar con antelación para no renovar.
     */
    public const ANNUAL_RENEWAL_LEAD_DAYS = 60;

    /** Días de antelación con los que se avisa del fin de un compromiso. */
    public const COMMITMENT_LEAD_DAYS = 30;

    /**
     * Días de antelación con los que se avisa del plazo para no renovar.
     *
     * Se avisa con el doble del plazo de preaviso, con un mínimo de una semana:
     * si el contrato pide 30 días, avisar el día 30 no sirve de nada.
     */
    public const NOTICE_LEAD_MULTIPLIER = 2;
    public const NOTICE_LEAD_MIN_DAYS = 7;

    /** Subida mínima, en porcentaje, para considerar que un cambio merece aviso. */
    public const PRICE_INCREASE_MIN_RATIO = 0.05;

    /** Un aviso de cobro a menos de tres días es urgente. */
    public const CRITICAL_CHARGE_DAYS = 3;

    /** Un aviso de renovación a menos de una semana es urgente. */
    public const CRITICAL_RENEWAL_DAYS = 7;

    /**
     * Cuántas propuestas pendientes se miran como máximo para generar avisos
     * de «servicio nuevo detectado».
     *
     * Es un tope de seguridad, no una regla de producto: una organización con
     * más de 500 propuestas sin revisar es patológica, y el barrido se hace en
     * memoria. Las propuestas que se queden fuera **no** pierden su aviso: la
     * resolución de estos avisos comprueba el estado real de la propuesta, no
     * la presencia en la lista, así que un aviso vivo no se cierra por no
     * haber salido en el barrido.
     */
    public const PENDING_DISCOVERY_SCAN_LIMIT = 500;

    public static function noticeLeadDays(int $noticePeriodDays): int
    {
        return max(self::NOTICE_LEAD_MIN_DAYS, $noticePeriodDays * self::NOTICE_LEAD_MULTIPLIER);
    }

    public static function chargeSeverity(int $daysUntil): AlertSeverity
    {
        return $daysUntil <= self::CRITICAL_CHARGE_DAYS ? AlertSeverity::CRITICAL : AlertSeverity::WARNING;
    }

    public static function renewalSeverity(int $daysUntil): AlertSeverity
    {
        return $daysUntil <= self::CRITICAL_RENEWAL_DAYS ? AlertSeverity::CRITICAL : AlertSeverity::WARNING;
    }

    /**
     * ¿Merece la pena avisar de esta subida?
     *
     * Una subida de céntimos genera ruido y hace que el usuario deje de leer los
     * avisos, que es el peor resultado posible.
     */
    public static function isNoteworthyIncrease(?float $ratio): bool
    {
        return null !== $ratio && $ratio >= self::PRICE_INCREASE_MIN_RATIO;
    }

    /**
     * ¿Este aviso se entrega por este canal si el usuario no ha dicho nada?
     *
     * La bandeja de la aplicación está siempre activa: es donde vive el aviso y
     * silenciarla no tendría sentido. El correo, en cambio, interrumpe, así que
     * por defecto está apagado y el usuario lo activa por tipo.
     *
     * Lo urgente es la excepción y no se puede silenciar: ver
     * `AlertSeverity::warrantsEmail()`. Un producto que existe para que no te
     * cobren algo que habías olvidado no puede callarse a tres días del cobro.
     */
    public static function defaultEnabled(AlertType $type, NotificationChannel $channel): bool
    {
        return match ($channel) {
            NotificationChannel::IN_APP => true,
            NotificationChannel::EMAIL => false,
        };
    }

    /**
     * Tipos de aviso que se generan, en el orden en que se muestran.
     *
     * @return list<AlertType>
     */
    public static function generatedTypes(): array
    {
        return [
            AlertType::UPCOMING_CHARGE,
            AlertType::NOTICE_DEADLINE,
            AlertType::UPCOMING_RENEWAL,
            AlertType::ANNUAL_RENEWAL,
            AlertType::COMMITMENT_ENDING,
            AlertType::PRICE_INCREASE,
            AlertType::DISCOVERY_PENDING,
            AlertType::NEW_SERVICE_DETECTED,
        ];
    }

    /**
     * Tipos de aviso que genera el servicio de coste, no el barrido general.
     *
     * El estado del presupuesto de IA lo lleva el módulo de IA, que el
     * generador general no debe conocer (Fase 14). Por eso estos avisos
     * tienen su propio servicio, `GenerateCostAlerts`, y su propio ciclo de
     * resolución: mezclarlos en el barrido obligaría a resolver avisos de
     * coste en cada pasada, y a que `AlertRules` supiera qué es un
     * presupuesto de IA.
     *
     * @return list<AlertType>
     */
    public static function costTypes(): array
    {
        return [
            AlertType::AI_BUDGET_WARNING,
            AlertType::AI_BUDGET_EXCEEDED,
        ];
    }

    /**
     * Todos los tipos de aviso, los genere quien los genere.
     *
     * La unión de los dos generadores tiene que cubrir el enum entero: si
     * alguien añade un tipo y no lo engancha a ningún generador, el aviso no
     * existiría nunca y nadie se enteraría.
     *
     * @return list<AlertType>
     */
    public static function allGeneratedTypes(): array
    {
        return array_merge(self::generatedTypes(), self::costTypes());
    }
}
