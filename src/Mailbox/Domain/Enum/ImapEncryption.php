<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Enum;

/**
 * Cifrado de la conexión IMAP.
 *
 * `SSL` (puerto 993) y `STARTTLS` (143) son las dos formas reales de hablar con
 * un servidor de correo hoy. `NONE` existe porque hay servidores internos que
 * solo ofrecen texto plano, pero la interfaz avisa de que las credenciales
 * viajarían sin proteger.
 */
enum ImapEncryption: string
{
    case SSL = 'ssl';
    case STARTTLS = 'starttls';
    case NONE = 'none';

    public function label(): string
    {
        return match ($this) {
            self::SSL => 'SSL/TLS (recomendado)',
            self::STARTTLS => 'STARTTLS',
            self::NONE => 'Sin cifrado',
        };
    }

    public function defaultPort(): int
    {
        return match ($this) {
            self::SSL => 993,
            self::STARTTLS => 143,
            self::NONE => 143,
        };
    }

    public function isSecure(): bool
    {
        return self::NONE !== $this;
    }
}
