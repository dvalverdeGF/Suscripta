<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Enum;

use App\Mailbox\Domain\ValueObject\MailProviderPreset;

/**
 * Cómo entra el correo en el sistema (ARCHITECTURE.md §4.5).
 *
 * `IMAP` es el caso general: cualquier servidor que hable IMAP estándar sirve,
 * sea el de un hosting propio o un buzón corporativo. Los demás casos existen
 * porque los grandes proveedores **ya no aceptan usuario y contraseña** y hay
 * que saber a cuál se está conectando para elegir el método de autenticación
 * correcto (D-93):
 *
 * - Gmail acepta contraseña de aplicación y OAuth2.
 * - Microsoft (outlook.com, hotmail.com) **solo** acepta OAuth2 desde 2024:
 *   el `LOGIN` con la contraseña correcta devuelve error de credenciales.
 * - Yahoo e iCloud aceptan únicamente contraseña de aplicación.
 *
 * El enum es también el catálogo de presets: el servidor, el puerto y el
 * cifrado de cada proveedor se declaran aquí una sola vez y la aplicación los
 * impone aunque el formulario mande otra cosa.
 */
enum EmailAccountProvider: string
{
    case IMAP = 'imap';
    case FORWARDING = 'forwarding';
    case GMAIL = 'gmail';
    case MICROSOFT = 'microsoft';
    case YAHOO = 'yahoo';
    case ICLOUD = 'icloud';

    public function label(): string
    {
        return match ($this) {
            self::IMAP => 'IMAP genérico',
            self::FORWARDING => 'Reenvío de correo',
            self::GMAIL => 'Gmail',
            self::MICROSOFT => 'Microsoft (Outlook, Hotmail)',
            self::YAHOO => 'Yahoo',
            self::ICLOUD => 'iCloud',
        };
    }

    /**
     * ¿Se puede conectar desde el formulario de la aplicación?
     *
     * `FORWARDING` no: esa vía no abre ninguna conexión, recibe correo
     * reenviado (D-21).
     */
    public function isImplemented(): bool
    {
        return self::FORWARDING !== $this;
    }

    /**
     * Servidor, puerto y cifrado del proveedor. `null` en el IMAP genérico.
     */
    public function preset(): ?MailProviderPreset
    {
        return match ($this) {
            self::GMAIL => new MailProviderPreset(
                host: 'imap.gmail.com',
                port: 993,
                encryption: ImapEncryption::SSL,
                appPasswordUrl: 'https://myaccount.google.com/apppasswords',
            ),
            self::MICROSOFT => new MailProviderPreset(
                host: 'outlook.office365.com',
                port: 993,
                encryption: ImapEncryption::SSL,
            ),
            self::YAHOO => new MailProviderPreset(
                host: 'imap.mail.yahoo.com',
                port: 993,
                encryption: ImapEncryption::SSL,
                appPasswordUrl: 'https://login.yahoo.com/account/security',
            ),
            self::ICLOUD => new MailProviderPreset(
                host: 'imap.mail.me.com',
                port: 993,
                encryption: ImapEncryption::SSL,
                appPasswordUrl: 'https://account.apple.com/account/manage',
            ),
            self::IMAP, self::FORWARDING => null,
        };
    }

    /**
     * ¿El proveedor ofrece OAuth2 para IMAP?
     */
    public function supportsOauth(): bool
    {
        return self::GMAIL === $this || self::MICROSOFT === $this;
    }

    /**
     * ¿El proveedor rechaza la autenticación con contraseña?
     *
     * Microsoft desactivó la autenticación básica para las cuentas personales:
     * no es que sea poco recomendable, es que no funciona.
     */
    public function requiresOauth(): bool
    {
        return self::MICROSOFT === $this;
    }

    /**
     * ¿Tiene sentido aceptar una contraseña (de aplicación) para este proveedor?
     */
    public function acceptsPassword(): bool
    {
        return self::IMAP === $this
            || self::GMAIL === $this
            || self::YAHOO === $this
            || self::ICLOUD === $this;
    }

    /**
     * Nombre del proveedor de OAuth2, tal y como lo conoce la configuración.
     */
    public function oauthKey(): ?string
    {
        return match ($this) {
            self::GMAIL => 'google',
            self::MICROSOFT => 'microsoft',
            default => null,
        };
    }
}
