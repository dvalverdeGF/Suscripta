<?php

declare(strict_types=1);

namespace App\Demo\UI\Twig;

use App\Demo\Application\DemoAccount;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * La credencial de la cuenta de demostración, para la pantalla de login.
 *
 * ## Solo en desarrollo
 *
 * En cualquier otro entorno devuelve `null` y la plantilla no pinta nada. La
 * comprobación es del **entorno**, no de un flag: así no cabe el error de
 * dejar el flag puesto en producción, que es el peor sitio donde puede
 * pasar.
 *
 * La contraseña se muestra porque la cuenta es de mentira y su único propósito
 * es dejar entrar a alguien en una demostración. En un entorno real la
 * contraseña no está en la configuración y la función devuelve `null` de
 * todas formas.
 */
final class DemoAccountRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly DemoAccount $account,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    /**
     * @return array{email: string, password: string, label: string}|null
     */
    public function account(): ?array
    {
        if ('dev' !== $this->environment) {
            return null;
        }

        if ('' === $this->account->email || '' === $this->account->password) {
            return null;
        }

        return [
            'email' => $this->account->email,
            'password' => $this->account->password,
            'label' => $this->account->label,
        ];
    }
}
