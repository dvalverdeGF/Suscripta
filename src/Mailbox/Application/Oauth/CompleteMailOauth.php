<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Oauth;

use App\Mailbox\Application\ConnectEmailAccount;
use App\Mailbox\Application\Dto\EmailAccountInput;
use App\Mailbox\Application\UpdateEmailAccount;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Exception\MailOauthException;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\ValueObject\EmailAccountCredentials;

use function hash_equals;
use function is_array;
use function is_string;
use function mb_strtolower;
use function sprintf;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

use function time;
use function trim;

/**
 * Segundo paso del flujo OAuth2: canjear el código y dejar el buzón conectado.
 *
 * Se reutilizan los casos de uso de siempre (`ConnectEmailAccount` y
 * `UpdateEmailAccount`): la única diferencia con una contraseña es de dónde
 * salen las credenciales. Así la comprobación de la conexión, el cifrado, las
 * cuotas y la auditoría son **los mismos** para los dos caminos, y un buzón de
 * Gmail no puede acabar con reglas distintas que uno de hosting.
 *
 * Si la dirección ya estaba conectada se **reautoriza** en vez de fallar: es la
 * salida cuando el usuario revoca el acceso desde su cuenta de Google o
 * Microsoft y el `refresh_token` deja de valer.
 */
final readonly class CompleteMailOauth
{
    public function __construct(
        private MailOauthProviderRegistry $providers,
        private RequestStack $requestStack,
        private ConnectEmailAccount $connectEmailAccount,
        private UpdateEmailAccount $updateEmailAccount,
        private EmailAccountRepositoryInterface $accounts,
    ) {
    }

    /**
     * @throws MailOauthException si el flujo no es válido o el proveedor falla
     */
    public function __invoke(
        EmailAccountProvider $provider,
        string $code,
        string $state,
        string $redirectUri,
        ?Uuid $actorUserId = null,
    ): EmailAccount {
        $flow = $this->consumeFlow($provider, $state);
        $tokens = $this->providers->for($provider)->exchangeCode($code, $redirectUri, $flow);

        $emailAddress = null === $tokens->emailAddress ? null : mb_strtolower(trim($tokens->emailAddress));

        if (null === $emailAddress || '' === $emailAddress) {
            throw new MailOauthException('No hemos podido leer la dirección de la cuenta que has autorizado. Inténtalo de nuevo.');
        }

        $input = new EmailAccountInput(
            emailAddress: $emailAddress,
            credentials: EmailAccountCredentials::oauth(
                accessToken: $tokens->accessToken,
                refreshToken: $tokens->refreshToken,
                expiresAt: $tokens->expiresAt,
            ),
            provider: $provider,
        );

        $existing = $this->accounts->findByAddress($emailAddress);

        if (null !== $existing && !$existing->isDeleted()) {
            return ($this->updateEmailAccount)($existing, $input, $actorUserId);
        }

        return ($this->connectEmailAccount)($input, $actorUserId);
    }

    /**
     * Valida el `state` contra la sesión y **consume** el flujo.
     *
     * El flujo se borra pase lo que pase: un `state` que se pueda reutilizar
     * deja de proteger de nada.
     *
     * @throws MailOauthException
     */
    private function consumeFlow(EmailAccountProvider $provider, string $state): string
    {
        $session = $this->requestStack->getSession();
        $flow = $session->get(StartMailOauth::SESSION_KEY);
        $session->remove(StartMailOauth::SESSION_KEY);

        if (!is_array($flow) || !is_string($flow['state'] ?? null) || !is_string($flow['verifier'] ?? null)) {
            throw new MailOauthException('La autorización ha caducado o ya se ha usado. Vuelve a empezar.');
        }

        if (($flow['expires_at'] ?? 0) < time()) {
            throw new MailOauthException('La autorización ha tardado demasiado. Vuelve a empezar.');
        }

        if (($flow['provider'] ?? null) !== $provider->value) {
            throw new MailOauthException(sprintf('La autorización en curso no es de %s. Vuelve a empezar.', $provider->label()));
        }

        if (!hash_equals($flow['state'], $state)) {
            throw new MailOauthException('La autorización no es válida. Vuelve a empezar.');
        }

        return $flow['verifier'];
    }
}
