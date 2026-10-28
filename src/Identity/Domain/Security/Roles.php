<?php

declare(strict_types=1);

namespace App\Identity\Domain\Security;

/**
 * Roles que concede la aplicación, además de los que añade Symfony.
 *
 * `ROLE_USER` no está aquí: lo añade `User::getRoles()` a todo el mundo y
 * declararlo daría la impresión de que se puede quitar.
 *
 * ## `ROLE_OPERATOR`
 *
 * Es el rol de quien **administra la instalación**, no de quien la usa. Hasta
 * la Fase 22 no hacía falta porque todo lo que había en el backoffice era de un
 * inquilino: cada organización veía lo suyo. El catálogo comercial es lo
 * primero que es de todos a la vez —los planes y sus precios son la oferta de
 * Suscripta— y por eso necesita un permiso que no venga de pertenecer a ninguna
 * organización (D-80). Se concede a mano:
 *
 *     bin/console app:user:grant-operator persona@ejemplo.com
 *
 * No se hereda de ningún plan: un cliente del plan `pro` no es operador por
 * pagar más.
 */
final class Roles
{
    public const string OPERATOR = 'ROLE_OPERATOR';

    private function __construct()
    {
    }
}
