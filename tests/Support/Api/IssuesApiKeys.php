<?php

declare(strict_types=1);

namespace App\Tests\Support\Api;

use App\Api\Application\Dto\ApiKeyInput;
use App\Api\Application\IssueApiKey;
use App\Api\Application\RevokeApiKey;
use App\Api\Domain\Entity\ApiKey;
use App\Api\Domain\Enum\ApiScope;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\TenantContext;

use function is_array;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Emite claves de API dentro de una prueba (Fase 18).
 *
 * ## Por qué no se emiten por HTTP
 *
 * El panel de claves exige sesión de usuario y un formulario con CSRF. Usarlo
 * para preparar el escenario de cada prueba de la API añadiría dos pasos que
 * no tienen nada que ver con lo que se está probando: si el formulario cambia,
 * fallan todas las pruebas de la API aunque la API esté perfecta.
 *
 * Aquí se llama al caso de uso, que es el mismo que usa el panel. La prueba
 * del panel sí recorre el formulario, porque ahí lo que se prueba es el
 * formulario.
 *
 * ## El contexto de inquilino
 *
 * `IssueApiKey` toma la organización del `TenantContext`. Se fija, se emite y
 * se **vuelve a dejar vacío**: si quedara fijado, la siguiente petición de la
 * prueba podría ver datos de una organización que no le toca y el test de
 * aislamiento estaría midiendo otra cosa.
 */
trait IssuesApiKeys
{
    /**
     * Registra una persona con su organización y devuelve las dos cosas.
     *
     * @return array{user: User, organization: Organization}
     */
    protected function registerOrganizationUser(string $email = 'api@example.com'): array
    {
        $user = self::getContainer()->get(RegisterUser::class)($email, 'Sup3rSecret!2026', 'Api Tester');

        $memberships = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($user);

        self::assertNotEmpty($memberships, 'El usuario recién registrado no tiene organización.');

        return ['user' => $user, 'organization' => $memberships[0]['organization']];
    }

    /**
     * Emite una clave y devuelve la entidad y el texto en claro.
     *
     * @param list<ApiScope> $scopes
     *
     * @return array{key: ApiKey, secret: string}
     */
    protected function issueApiKey(
        Organization $organization,
        string $name = 'Clave de prueba',
        array $scopes = [ApiScope::Read],
        ?int $expiresInDays = null,
        ?User $actor = null,
    ): array {
        $tenant = self::getContainer()->get(TenantContext::class);

        $tenant->setOrganizationId($organization->getId());

        try {
            /** @var array{key: ApiKey, secret: string} $issued */
            $issued = self::getContainer()->get(IssueApiKey::class)(
                new ApiKeyInput($name, $scopes, $expiresInDays),
                $actor?->getId(),
            );
        } finally {
            $tenant->setOrganizationId(null);
        }

        return $issued;
    }

    /**
     * Revoca una clave como lo haría el panel.
     */
    protected function revokeApiKey(Organization $organization, ApiKey $apiKey): ApiKey
    {
        $tenant = self::getContainer()->get(TenantContext::class);

        $tenant->setOrganizationId($organization->getId());

        try {
            return self::getContainer()->get(RevokeApiKey::class)($apiKey->getId());
        } finally {
            $tenant->setOrganizationId(null);
        }
    }

    /**
     * Pide una ruta de la API con la clave en la cabecera `Authorization`.
     */
    protected function apiGet(KernelBrowser $client, string $path, string $secret): void
    {
        $client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
    }

    /**
     * Cuerpo JSON de la última respuesta, ya decodificado.
     *
     * @return array<string, mixed>
     */
    protected function jsonBody(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();

        self::assertIsString($content);

        $decoded = json_decode($content, true);

        self::assertTrue(is_array($decoded), 'La respuesta no es un objeto JSON: '.substr($content, 0, 200));

        return $decoded;
    }
}
