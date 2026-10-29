<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Exception;

/**
 * Fallo del flujo OAuth2 con el proveedor de correo.
 *
 * Cubre lo que puede salir mal antes de tener un buzón: que las credenciales
 * de la aplicación no estén configuradas, que el usuario cancele la
 * autorización, que el `code` ya no valga o que el `refresh_token` esté
 * revocado. El mensaje es apto para mostrarse: nunca incluye tokens
 * (SECURITY.md §3).
 */
class MailOauthException extends ImapConnectionException
{
}
