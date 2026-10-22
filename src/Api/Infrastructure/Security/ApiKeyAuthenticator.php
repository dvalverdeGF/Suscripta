<?php

declare(strict_types=1);

namespace App\Api\Infrastructure\Security;

use App\Api\Application\AuthenticateApiKey;
use App\Api\Domain\Entity\ApiKey;
use App\Api\Domain\Exception\InvalidApiKeyException;
use App\Shared\Application\TenantContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

use function is_string;
use function strlen;
use function strncasecmp;
use function substr;
use function trim;

/**
 * Autentica las peticiones de la API con una clave de API.
 *
 * ## Dónde se busca la clave
 *
 * - `Authorization: Bearer sk_…` — la forma que espera cualquier cliente
 *   HTTP moderno, y la que aparece en las cabeceras de autenticación
 *   estándar.
 * - `X-Api-Key: sk_…` — la forma que usa la mayoría de las API de
 *   administración, y la única que sobrevive a algunos intermediarios que
 *   borran la cabecera `Authorization`.
 *
 * ## Qué pasa al autenticar
 *
 * Además de dar la identidad, el autenticador **fija la organización activa**
 * en el `TenantContext`. Es lo que activa el filtro de Doctrine: desde ese
 * momento, todas las consultas de la petición están restringidas a la
 * organización de la clave. Sin esto, una consulta sin filtro dejaría ver
 * datos de otros inquilinos.
 *
 * Se fija aquí, y no en un listener posterior, porque en este punto ya se
 * sabe de qué organización se trata: cualquier código que se ejecutara
 * entre la autenticación y el listener tendría el contexto sin restringir.
 *
 * ## Por qué `SelfValidatingPassport`
 *
 * La credencial se **comprueba** contra la base de datos en cada petición
 * (para saber si se ha revocado o ha caducado), así que no hay nada que
 * «validar» después: la comprobación ya está hecha. `SelfValidatingPassport`
 * dice exactamente eso: la contraseña es válida porque el autenticador lo ha
 * comprobado.
 */
final class ApiKeyAuthenticator extends AbstractAuthenticator
{
    /**
     * Prefijo de la cabecera `Authorization` que lleva la clave.
     */
    private const BEARER_PREFIX = 'bearer ';

    public function __construct(
        private readonly AuthenticateApiKey $authenticate,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function supports(Request $request): bool
    {
        return null !== $this->extractSecret($request);
    }

    public function authenticate(Request $request): Passport
    {
        $secret = $this->extractSecret($request);

        if (null === $secret) {
            throw new AuthenticationException('Falta la clave de API.');
        }

        try {
            $apiKey = ($this->authenticate)($secret);
        } catch (InvalidApiKeyException $exception) {
            // La clave no existe, ha caducado o está revocada: para quien
            // llama, todo es lo mismo —«no autenticado»—. El motivo se guarda
            // en el log, no en la respuesta: decir *por qué* falla una clave
            // ayuda a quien prueba claves ajenas.
            throw new AuthenticationException('La clave de API no es válida.', 0, $exception);
        }

        // A partir de aquí, la petición está dentro de una organización.
        $this->tenantContext->setOrganizationId($apiKey->getOrganizationId());

        return new SelfValidatingPassport(
            new UserBadge(
                $apiKey->getId()->toRfc4122(),
                static fn (): ApiClient => self::toClient($apiKey),
            ),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // La respuesta la construye el punto de entrada del firewall (`api`),
        // para que el 401 tenga siempre la misma forma.
        return null;
    }

    /**
     * La clave que lleva la petición, si la hay.
     */
    private function extractSecret(Request $request): ?string
    {
        $authorization = $request->headers->get('Authorization');

        if (is_string($authorization) && 0 === strncasecmp($authorization, self::BEARER_PREFIX, \strlen(self::BEARER_PREFIX))) {
            $secret = trim(substr($authorization, \strlen(self::BEARER_PREFIX)));

            if ('' !== $secret) {
                return $secret;
            }
        }

        $header = $request->headers->get('X-Api-Key');

        if (is_string($header) && '' !== trim($header)) {
            return trim($header);
        }

        return null;
    }

    private static function toClient(ApiKey $apiKey): ApiClient
    {
        return new ApiClient(
            organizationId: $apiKey->getOrganizationId(),
            apiKeyId: $apiKey->getId(),
            name: $apiKey->getName(),
            scopes: $apiKey->getScopes(),
        );
    }
}
