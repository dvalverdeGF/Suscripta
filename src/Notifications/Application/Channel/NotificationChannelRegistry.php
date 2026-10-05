<?php

declare(strict_types=1);

namespace App\Notifications\Application\Channel;

use App\Notifications\Domain\Enum\NotificationChannel;
use App\Shared\Domain\Exception\InvalidArgumentException;

use function sprintf;

/**
 * Todos los canales de entrega disponibles, indexados por su enum.
 *
 * Se recogen por etiqueta de servicio, así que añadir un canal nuevo (por
 * ejemplo, un webhook) es escribir la clase y nada más.
 */
final readonly class NotificationChannelRegistry
{
    /** @var array<string, NotificationChannelInterface> */
    private array $channels;

    /**
     * @param iterable<NotificationChannelInterface> $channels
     */
    public function __construct(iterable $channels)
    {
        $indexed = [];

        foreach ($channels as $channel) {
            $indexed[$channel->channel()->value] = $channel;
        }

        $this->channels = $indexed;
    }

    public function get(NotificationChannel $channel): NotificationChannelInterface
    {
        return $this->channels[$channel->value]
            ?? throw new InvalidArgumentException(sprintf('No hay ningún canal registrado para «%s».', $channel->value));
    }

    /**
     * @return list<NotificationChannelInterface>
     */
    public function all(): array
    {
        return array_values($this->channels);
    }
}
