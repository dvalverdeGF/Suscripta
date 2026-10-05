<?php

declare(strict_types=1);

namespace App\Notifications\Application\Channel;

use App\Identity\Domain\Entity\User;
use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\NotificationChannel;

/**
 * El canal de la propia aplicación.
 *
 * No hay nada que enviar: el aviso **es** la fila de `alert`, y la bandeja la
 * lee de ahí. El canal existe igualmente para que la entrega quede registrada
 * como cualquier otra y el usuario pueda ver que el aviso está en su bandeja.
 */
final class InAppChannel implements NotificationChannelInterface
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::IN_APP;
    }

    public function deliver(Alert $alert, User $user): DeliveryResult
    {
        return DeliveryResult::sent();
    }
}
