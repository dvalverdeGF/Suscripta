<?php

declare(strict_types=1);

namespace App\Notifications\Application\Dto;

/**
 * La matriz de preferencias tal y como llega del formulario.
 *
 * Es un array anidado y no una propiedad por casilla porque la matriz crece
 * sola: añadir un tipo de aviso o un canal nuevo no debe obligar a tocar este
 * DTO ni la plantilla.
 */
final class NotificationPreferenceFormData
{
    /** @var array<string, array<string, bool>> tipo => canal => activo */
    public array $enabled = [];
}
