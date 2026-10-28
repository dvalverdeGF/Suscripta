<?php

declare(strict_types=1);

namespace App\Api\Infrastructure\Security;

use App\Api\Domain\Enum\ApiScope;
use App\Shared\Domain\Contract\TenantAwareInterface;

use function strtoupper;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * La identidad de una llamada a la API.
 *
 * ## No es un usuario
 *
 * Implementa `UserInterface` porque es lo que espera el contenedor de
 * seguridad, pero **no** es una persona: no tiene contraseña, ni nombre, ni
 * correo, ni puede entrar en el backoffice. Da acceso a una **organización**
 * con un conjunto de ámbitos.
 *
 * ## Por qué esto importa
 *
 * Si una clave de API se Authenticara como si fuera un usuario, cualquier
 * código que compruebe «¿es el usuario X?» —el panel, el registro de
 * auditoría, las notificaciones— la trataría como una persona. Con un tipo
 * propio, cada sitio que necesita saber «¿esto es una persona o una
 * integración?» puede preguntarlo, y el backoffice nunca acepta una clave de
 * API como sesión.
 */
final readonly class ApiClient implements UserInterface, TenantAwareInterface
{
    /**
     * @param list<ApiScope> $scopes
     */
    public function __construct(
        private Uuid $organizationId,
        private Uuid $apiKeyId,
        private string $name,
        private array $scopes,
    ) {
    }

    public function getOrganizationId(): Uuid
    {
        return $this->organizationId;
    }

    public function setOrganizationId(Uuid $organizationId): void
    {
        // Una identidad de API pertenece a una organización: no se cambia.
    }

    public function getApiKeyId(): Uuid
    {
        return $this->apiKeyId;
    }

    /**
     * @return list<ApiScope>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /**
     * El nombre que le dio el operador a la clave.
     *
     * No sirve para autenticarse (eso lo hace la huella), pero identifica la
     * credencial en los registros y en las pantallas: «la clave de la
     * integración con el ERP» es mucho más útil que un UUID.
     */
    public function getName(): string
    {
        return $this->name;
    }

    public function allows(ApiScope $scope): bool
    {
        foreach ($this->scopes as $granted) {
            if ($granted->covers($scope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * El identificador de seguridad es el de la clave, no el de la
     * organización: dos claves de la misma organización son dos identidades
     * distintas y se pueden auditar por separado.
     */
    public function getUserIdentifier(): string
    {
        return 'api:'.$this->apiKeyId->toRfc4122();
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = ['ROLE_API'];

        foreach ($this->scopes as $scope) {
            $roles[] = 'SCOPE_'.strtoupper($scope->value);
        }

        return $roles;
    }

    public function eraseCredentials(): void
    {
        // No hay credenciales que borrar: la clave se verifica en cada
        // petición y no se guarda en ningún sitio.
    }
}
