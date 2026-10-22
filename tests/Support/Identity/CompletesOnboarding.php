<?php

declare(strict_types=1);

namespace App\Tests\Support\Identity;

use App\Identity\Application\CompleteOnboarding;
use App\Identity\Domain\Entity\User;

/**
 * Da por terminada la guía de bienvenida (Fase 17).
 *
 * ## Por qué hace falta
 *
 * Mientras la guía no esté terminada, el panel manda a `/onboarding`. En una
 * prueba que no va de la guía, ese salto es ruido: lo que se quiere probar es
 * el panel, no la redirección. Con este rasgo la prueba dice explícitamente
 * «la guía ya está hecha» y el panel se comporta como en producción.
 *
 * Las pruebas que sí van de la guía **no** usan este rasgo: ahí el estado
 * inicial (sin terminar) es justo lo que se quiere probar.
 */
trait CompletesOnboarding
{
    protected function completeOnboarding(User $user): User
    {
        return self::getContainer()->get(CompleteOnboarding::class)($user);
    }
}
