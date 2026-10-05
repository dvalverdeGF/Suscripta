<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Repository;

use App\Notifications\Domain\Entity\NotificationPreference;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Enum\NotificationChannel;
use Symfony\Component\Uid\Uuid;

interface NotificationPreferenceRepositoryInterface
{
    public function find(Uuid $id): ?NotificationPreference;

    public function findOne(Uuid $userId, AlertType $type, NotificationChannel $channel): ?NotificationPreference;

    /**
     * @return list<NotificationPreference>
     */
    public function findForUser(Uuid $userId): array;

    /**
     * Preferencias del usuario indexadas por «tipo|canal», para resolver el
     * valor efectivo de cada combinación sin una consulta por celda.
     *
     * @return array<string, bool>
     */
    public function findEnabledMapForUser(Uuid $userId): array;

    public function save(NotificationPreference $preference): void;

    /**
     * Borra la desviación. Volver al valor por defecto no es «guardar un
     * valor», es dejar de tener opinión, y así lo refleja la base de datos.
     */
    public function remove(NotificationPreference $preference): void;
}
