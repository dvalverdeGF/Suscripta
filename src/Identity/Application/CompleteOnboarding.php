<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Domain\Enum\AuditAction;

/**
 * Marca la guía de bienvenida como terminada (Fase 17).
 *
 * ## Por qué hay que guardarlo
 *
 * La guía se enseña **una vez**. Si se limitara a enseñarla cada vez que se
 * entra al panel, un usuario que ya lo tiene todo configurado seguiría viendo
 * una lista de tareas hechas cada vez que abre la aplicación. Y si no se
 * guardara, no se podría saber a quién hay que empujarle la guía.
 *
 * ## Por qué se guarda en el usuario y no en la organización
 *
 * Porque la guía la recorre **una persona**, no una organización. En una
 * organización con tres personas, cada una tiene su propio camino: una ya
 * ha conectado el buzón, otra acaba de llegar. El estado de la organización
 * (buzones conectados, servicios creados) se calcula aparte, en vivo.
 */
final readonly class CompleteOnboarding
{
    public function __construct(
        private UserRepositoryInterface $users,
        private AuditLoggerInterface $auditLogger,
        private Clock $clock,
    ) {
    }

    public function __invoke(User $user): User
    {
        // Terminar la guía dos veces no es un error: es un clic de más.
        if ($user->isOnboardingCompleted()) {
            return $user;
        }

        $user->markOnboardingCompleted($this->clock->now());

        $this->users->save($user);

        $this->auditLogger->log(
            action: AuditAction::USER_ONBOARDING_COMPLETED,
            targetType: 'user',
            targetId: $user->getId()->toRfc4122(),
        );

        return $user;
    }
}
