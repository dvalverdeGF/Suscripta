<?php

declare(strict_types=1);

namespace App\Demo\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * La cuenta de demostración: quién es y con qué contraseña entra.
 *
 * ## De dónde salen las credenciales
 *
 * De la configuración (`DEMO_ACCOUNT_EMAIL` y `DEMO_ACCOUNT_PASSWORD`), no de
 * una constante en el código. Así el mismo binario sirve para el entorno de
 * desarrollo y para el de pruebas sin tener que desplegar, y la contraseña
 * puede estar en `.env.local` —que no se versiona— en lugar de en el
 * repositorio.
 *
 * ## Por qué una clase y no un parámetro suelto
 *
 * Porque la credencial viaja a tres sitios: el comando que siembra la cuenta,
 * la pantalla de login de desarrollo y —cuando exista— la documentación. Que
 * los tres lean el mismo objeto evita que se desincronicen y que alguien
 * cambie la contraseña en un sitio y olvide el otro.
 */
final readonly class DemoAccount
{
    public function __construct(
        #[Autowire('%app.demo.email%')]
        public string $email,
        #[Autowire('%app.demo.password%')]
        public string $password,
        #[Autowire('%app.demo.label%')]
        public string $label = 'Cuenta de demostración',
    ) {
    }
}
