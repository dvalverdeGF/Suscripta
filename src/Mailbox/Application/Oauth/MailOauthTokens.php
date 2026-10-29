<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Oauth;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * Lo que devuelve el proveedor de OAuth2 al canjear el código o al renovar.
 *
 * `refreshToken` es opcional porque solo llega en el canje y en la primera
 * autorización; las renovaciones posteriores no lo repiten y hay que conservar
 * el que ya se tenía.
 *
 * `emailAddress` se lee del `id_token` y es la identidad de la cuenta: es lo que
 * permite crear el buzón sin preguntarle al usuario qué dirección acaba de
 * autorizar.
 */
final readonly class MailOauthTokens
{
    public function __construct(
        #[SensitiveParameter]
        public string $accessToken,
        #[SensitiveParameter]
        public ?string $refreshToken,
        public DateTimeImmutable $expiresAt,
        public ?string $emailAddress = null,
    ) {
    }
}
