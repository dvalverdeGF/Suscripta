<?php

declare(strict_types=1);

namespace App\Notifications\Application\Channel;

use App\Identity\Domain\Entity\User;
use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\NotificationChannel;

/**
 * Un medio de entrega de avisos (ARCHITECTURE.md §4.7).
 *
 * El canal solo sabe **entregar**; no decide si debe hacerlo. La política
 * (preferencias del usuario, severidad mínima) vive en `DispatchNotifications`,
 * para que añadir un canal nuevo no obligue a duplicar reglas de negocio.
 */
interface NotificationChannelInterface
{
    public function channel(): NotificationChannel;

    /**
     * Entrega el aviso. No debe lanzar excepciones: un fallo de red al enviar
     * un correo no puede tumbar el resto de la bandeja.
     */
    public function deliver(Alert $alert, User $user): DeliveryResult;
}
