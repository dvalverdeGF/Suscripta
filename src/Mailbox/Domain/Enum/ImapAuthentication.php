<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Enum;

/**
 * Cómo se autentica la sesión IMAP.
 *
 * `PASSWORD` cubre la contraseña normal y la contraseña de aplicación: para el
 * servidor son lo mismo, un secreto que se manda en el `LOGIN`. `OAUTH` es
 * `AUTHENTICATE XOAUTH2`, donde el secreto es un **token de acceso** de vida
 * corta que se renueva con el `refresh_token` (D-93).
 *
 * La diferencia no es cosmética: un servidor que exige OAuth rechaza el
 * `LOGIN` con la contraseña correcta, así que elegir mal aquí produce un error
 * de credenciales que confunde al usuario.
 */
enum ImapAuthentication: string
{
    case PASSWORD = 'password';
    case OAUTH = 'oauth';
}
