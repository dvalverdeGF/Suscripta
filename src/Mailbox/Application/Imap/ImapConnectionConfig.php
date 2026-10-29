<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Imap;

use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Enum\ImapAuthentication;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\ValueObject\EmailAccountCredentials;
use SensitiveParameter;

use function sprintf;

/**
 * Parámetros efímeros para abrir una conexión IMAP.
 *
 * Vive solo en memoria durante la operación: la contraseña —o el token de
 * acceso— se descifra justo antes de conectar y se descarta al terminar
 * (SECURITY.md §3).
 *
 * `authentication` no es un detalle del cliente: un buzón de Microsoft solo
 * acepta `AUTHENTICATE XOAUTH2`, y mandarle el token como contraseña en un
 * `LOGIN` devuelve un error de credenciales que parece un fallo del usuario
 * (D-93).
 */
final readonly class ImapConnectionConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public ImapEncryption $encryption,
        public string $username,
        #[SensitiveParameter]
        public string $password,
        public int $timeout = 30,
        public ImapAuthentication $authentication = ImapAuthentication::PASSWORD,
    ) {
    }

    /**
     * Configuración de un buzón ya guardado a partir de sus credenciales.
     *
     * Es el único sitio que traduce «buzón + credenciales» a «parámetros de
     * conexión»: antes estaba copiado en cuatro servicios y cada copia podía
     * quedarse atrás (D-93).
     */
    public static function forAccount(EmailAccount $account, EmailAccountCredentials $credentials): self
    {
        return new self(
            host: (string) $account->getImapHost(),
            port: (int) $account->getImapPort(),
            encryption: $account->getImapEncryption() ?? ImapEncryption::SSL,
            username: (string) $account->getImapUsername(),
            password: $credentials->secret(),
            authentication: $credentials->authentication(),
        );
    }

    /**
     * Representación segura para logs: nunca expone la contraseña.
     */
    public function describe(): string
    {
        return sprintf(
            '%s@%s:%d (%s, %s)',
            $this->username,
            $this->host,
            $this->port,
            $this->encryption->value,
            $this->authentication->value,
        );
    }
}
