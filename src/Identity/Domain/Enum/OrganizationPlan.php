<?php

declare(strict_types=1);

namespace App\Identity\Domain\Enum;

/**
 * Plan contratado por la organización (Fase 17).
 *
 * El plan no es una etiqueta comercial: es **la fuente de los límites** que
 * aplica la aplicación. Cada plan declara cuánto puede tener la organización
 * (servicios, buzones, documentos) y si puede usar inteligencia artificial.
 *
 * ## Por qué un enum y no un texto
 *
 * El plan se persiste en la base de datos y decide qué se puede hacer. Si
 * fuera un texto libre, un valor mal escrito dejaría a la organización sin
 * límites (o sin servicios) sin que nadie se entere. Con el enum, un plan
 * desconocido es un error al leer la fila, no un comportamiento silencioso.
 *
 * ## Dónde están los límites
 *
 * En `config/packages/entitlements.yaml`, no aquí. El enum dice **qué planes
 * existen**; los números son configuración de producto y pueden cambiar sin
 * desplegar código (D-64).
 */
enum OrganizationPlan: string
{
    /** Plan de entrada: límites reducidos y sin inteligencia artificial. */
    case FREE = 'free';

    /** Plan de pago: más margen y con inteligencia artificial disponible. */
    case PRO = 'pro';

    /**
     * Plan con el que se crea una organización.
     *
     * Nadie empieza en un plan de pago: el plan se cambia cuando hay una
     * suscripción detrás, no porque el formulario de alta lo pida.
     */
    public static function default(): self
    {
        return self::FREE;
    }

    /**
     * Nombre legible del plan.
     */
    public function label(): string
    {
        return match ($this) {
            self::FREE => 'Gratis',
            self::PRO => 'Profesional',
        };
    }
}
