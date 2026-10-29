<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailbox\Domain\Enum;

use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Enum\ImapEncryption;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * El catálogo de proveedores es la única fuente de verdad sobre a qué servidor
 * se conecta cada buzón y con qué método. Si un preset se equivoca, la
 * aplicación deja de poder conectar con ese proveedor y el formulario no lo
 * detecta: no hay ningún campo que el usuario pueda corregir.
 */
#[CoversClass(EmailAccountProvider::class)]
final class EmailAccountProviderTest extends TestCase
{
    public function testEveryProviderHasALabel(): void
    {
        foreach (EmailAccountProvider::cases() as $provider) {
            self::assertNotSame('', $provider->label(), $provider->value);
        }
    }

    public function testEveryProviderExceptForwardingCanBeConnected(): void
    {
        foreach (EmailAccountProvider::cases() as $provider) {
            self::assertSame(EmailAccountProvider::FORWARDING !== $provider, $provider->isImplemented(), $provider->value);
        }
    }

    public function testKnownProvidersPointAtTheirOwnServerOverTls(): void
    {
        $expected = [
            'gmail' => 'imap.gmail.com',
            'microsoft' => 'outlook.office365.com',
            'yahoo' => 'imap.mail.yahoo.com',
            'icloud' => 'imap.mail.me.com',
        ];

        foreach ($expected as $value => $host) {
            $preset = EmailAccountProvider::from($value)->preset();

            self::assertNotNull($preset, $value);
            self::assertSame($host, $preset->host, $value);
            self::assertSame(993, $preset->port, $value);
            self::assertSame(ImapEncryption::SSL, $preset->encryption, $value);
        }
    }

    public function testGenericImapHasNoPreset(): void
    {
        // El servidor es un dato del usuario: no hay nada que imponer ni que
        // adivinar en el catálogo.
        self::assertNull(EmailAccountProvider::IMAP->preset());
        self::assertNull(EmailAccountProvider::FORWARDING->preset());
    }

    public function testOnlyMicrosoftRequiresOauth(): void
    {
        // Microsoft desactivó la autenticación básica en 2024: no es una
        // preferencia, es que `LOGIN` ya no funciona.
        self::assertTrue(EmailAccountProvider::MICROSOFT->requiresOauth());

        foreach ([EmailAccountProvider::IMAP, EmailAccountProvider::GMAIL, EmailAccountProvider::YAHOO, EmailAccountProvider::ICLOUD] as $provider) {
            self::assertFalse($provider->requiresOauth(), $provider->value);
        }
    }

    public function testPasswordIsRefusedWhereTheProviderDoesNotAcceptIt(): void
    {
        self::assertFalse(EmailAccountProvider::MICROSOFT->acceptsPassword());
        self::assertFalse(EmailAccountProvider::FORWARDING->acceptsPassword());

        foreach ([EmailAccountProvider::IMAP, EmailAccountProvider::GMAIL, EmailAccountProvider::YAHOO, EmailAccountProvider::ICLOUD] as $provider) {
            self::assertTrue($provider->acceptsPassword(), $provider->value);
        }
    }

    public function testOnlyGoogleAndMicrosoftHaveOauth(): void
    {
        self::assertSame('google', EmailAccountProvider::GMAIL->oauthKey());
        self::assertSame('microsoft', EmailAccountProvider::MICROSOFT->oauthKey());

        foreach ([EmailAccountProvider::IMAP, EmailAccountProvider::YAHOO, EmailAccountProvider::ICLOUD, EmailAccountProvider::FORWARDING] as $provider) {
            self::assertNull($provider->oauthKey(), $provider->value);
            self::assertFalse($provider->supportsOauth(), $provider->value);
        }
    }

    public function testProvidersThatNeedAnAppPasswordLinkToIt(): void
    {
        // Sin el enlace, el usuario tiene que buscar la página él mismo y la
        // mitad de las veces acaba en una guía de 2019.
        foreach ([EmailAccountProvider::GMAIL, EmailAccountProvider::YAHOO, EmailAccountProvider::ICLOUD] as $provider) {
            self::assertNotNull($provider->preset()?->appPasswordUrl, $provider->value);
        }

        self::assertNull(EmailAccountProvider::MICROSOFT->preset()?->appPasswordUrl);
    }
}
