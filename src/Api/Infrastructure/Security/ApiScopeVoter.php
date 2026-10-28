<?php

declare(strict_types=1);

namespace App\Api\Infrastructure\Security;

use App\Api\Domain\Enum\ApiScope;

use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Decide si una clave de API tiene el ámbito que pide un endpoint.
 *
 * ## Por qué hace falta un voter propio
 *
 * Symfony no tiene ámbitos: conoce **roles** (`ROLE_ADMIN`) y poco más. La
 * comprobación `#[IsGranted('SCOPE_READ')]` que hacen los controladores de la
 * API no encaja con ningún voter de los que trae el framework, y el efecto es
 * silencioso y peligroso: sin un voter que atienda ese atributo, todos los
 * voters se abstienen, la estrategia afirmativa aborta y **la petición se
 * deniega**. Un `403` en todos los endpoints, sin explicación.
 *
 * ## `SCOPE_` y no `ROLE_`
 *
 * Se usa el prefijo `SCOPE_` a propósito. El voter de roles de Symfony
 * (`RoleVoter`) acepta cualquier atributo que empiece por `ROLE_`, y como
 * `ApiClient` expone sus ámbitos como roles, un `ROLE_SCOPE_READ` se
 * confundiría con un rol de la aplicación. Con `SCOPE_` no hay solapamiento:
 * los ámbitos de la API y los roles del backoffice son dos cosas distintas.
 *
 * ## Por qué `supportsAttribute()` está sobrescrito
 *
 * La clase abstracta `Voter` acepta **cualquier** atributo por defecto
 * (`supportsAttribute()` devuelve `true`). Si no se sobrescribe, este voter se
 * pronuncia sobre todos los atributos de la aplicación —incluidos `ROLE_USER`
 * y `IS_AUTHENTICATED_FULLY`— y decide su resultado. Eso no es un detalle: un
 * voter que apoya en todo lo que ve termina denegando el acceso a las páginas
 * del backoffice.
 *
 * ## Fallo cerrado
 *
 * Cuando la identidad no es una clave de API (una persona del panel, por
 * ejemplo), este voter **deniega**. Es lo contrario de la abstención, y es lo
 * correcto: un `SCOPE_*` solo aparece en endpoints de la API, así que quien
 * llega ahí sin una clave de API no debería pasar. Quien llega con un
 * atributo que no es de ámbito (un `ROLE_USER` del panel) nunca hace que este
 * voter se pronuncie, porque `supportsAttribute()` solo acepta `SCOPE_`.
 *
 * @extends Voter<string, mixed>
 */
final class ApiScopeVoter extends Voter
{
    /**
     * Prefijo de los atributos de ámbito.
     */
    private const PREFIX = 'SCOPE_';

    /**
     * El ámbito que pide el atributo, si lo pide.
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return null !== $this->scopeFor($attribute);
    }

    /**
     * Solo atiende atributos de ámbito.
     *
     * Es el método que decide si este voter se pronuncia sobre un atributo
     * concreto. Sin sobrescribirlo, se pronunciaría sobre todos.
     */
    public function supportsAttribute(string $attribute): bool
    {
        return str_starts_with($attribute, self::PREFIX);
    }

    /**
     * La identidad de la petición tiene el ámbito pedido.
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof ApiClient) {
            // No es una clave de API: este voter no tiene nada que decir.
            return false;
        }

        $scope = $this->scopeFor($attribute);

        if (null === $scope) {
            return false;
        }

        return $user->allows($scope);
    }

    /**
     * Traduce `SCOPE_READ` al ámbito `read`.
     */
    private function scopeFor(string $attribute): ?ApiScope
    {
        if (!str_starts_with($attribute, self::PREFIX)) {
            return null;
        }

        return ApiScope::tryFrom(strtolower(substr($attribute, strlen(self::PREFIX))));
    }
}
