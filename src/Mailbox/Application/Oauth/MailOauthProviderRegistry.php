<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Oauth;

use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Exception\MailOauthException;

use function sprintf;

/**
 * Los proveedores de OAuth2 que hay instalados, indexados por proveedor.
 *
 * Se recogen por etiqueta (`app.mail_oauth_provider`), igual que los canales de
 * notificación o los parsers de proveedor: el caso de uso no conoce ni el
 * nombre ni el número de adaptadores que existen.
 */
final readonly class MailOauthProviderRegistry
{
    /** @var array<string, MailOauthProviderInterface> */
    private array $providers;

    /**
     * @param iterable<MailOauthProviderInterface> $providers
     */
    public function __construct(iterable $providers)
    {
        $indexed = [];

        foreach ($providers as $provider) {
            $indexed[$provider->provider()->value] = $provider;
        }

        $this->providers = $indexed;
    }

    /**
     * @throws MailOauthException si el proveedor no tiene adaptador instalado
     */
    public function for(EmailAccountProvider $provider): MailOauthProviderInterface
    {
        return $this->providers[$provider->value]
            ?? throw new MailOauthException(sprintf('%s no se puede conectar con OAuth.', $provider->label()));
    }

    public function isConfigured(EmailAccountProvider $provider): bool
    {
        $adapter = $this->providers[$provider->value] ?? null;

        return null !== $adapter && $adapter->isConfigured();
    }

    /**
     * Proveedores que hoy se pueden conectar con OAuth de verdad.
     *
     * La interfaz pinta un botón por cada uno; el resto de proveedores se
     * conectan con contraseña de aplicación desde el formulario.
     *
     * @return list<EmailAccountProvider>
     */
    public function configuredProviders(): array
    {
        $providers = [];

        foreach (EmailAccountProvider::cases() as $provider) {
            if ($this->isConfigured($provider)) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }
}
