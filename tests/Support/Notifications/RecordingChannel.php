<?php

declare(strict_types=1);

namespace App\Tests\Support\Notifications;

use App\Identity\Domain\Entity\User;
use App\Notifications\Application\Channel\DeliveryResult;
use App\Notifications\Application\Channel\NotificationChannelInterface;
use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\NotificationChannel;

use function count;

/**
 * Canal de prueba que recuerda lo que le han pedido entregar.
 *
 * Permite comprobar la política de entrega (qué se envía y qué no) sin depender
 * del correo ni de la base de datos.
 */
final class RecordingChannel implements NotificationChannelInterface
{
    /** @var list<array{alert: Alert, user: User}> */
    public array $deliveries = [];

    public function __construct(
        private readonly NotificationChannel $channel,
        private ?DeliveryResult $result = null,
    ) {
    }

    public function channel(): NotificationChannel
    {
        return $this->channel;
    }

    public function deliver(Alert $alert, User $user): DeliveryResult
    {
        $this->deliveries[] = ['alert' => $alert, 'user' => $user];

        return $this->result ?? DeliveryResult::sent();
    }

    public function willFail(string $reason): void
    {
        $this->result = DeliveryResult::failed($reason);
    }

    public function willSkip(string $reason): void
    {
        $this->result = DeliveryResult::skipped($reason);
    }

    public function deliveryCount(): int
    {
        return count($this->deliveries);
    }
}
