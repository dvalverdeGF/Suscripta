<?php

declare(strict_types=1);

namespace App\Api\Application;

use App\Api\Application\Dto\ApiKeyInput;
use App\Api\Domain\Entity\ApiKey;
use App\Api\Domain\Enum\ApiScope;
use App\Api\Domain\Repository\ApiKeyRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Exception\InvalidArgumentException;
use DateTimeImmutable;

use function mb_strlen;
use function sprintf;

use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * Emite una clave de API para la organización activa.
 *
 * ## La clave en claro se ve una vez
 *
 * El caso de uso devuelve la clave **en claro** junto con la entidad. Quien
 * la guarda es el panel, que la enseña una vez y se olvida. No hay manera de
 * recuperarla después, y esa es la idea: una credencial que se puede volver a
 * leer es una credencial que acaba en un archivo de texto.
 *
 * ## Los ámbitos se amplían solos
 *
 * Si se pide `write`, se guarda también `read`: quien puede escribir puede
 * leer. Se hace **al emitir**, no al comprobar, para que el conjunto de
 * ámbitos que se ve en el panel sea el que la clave lleva de verdad.
 *
 * ## Solo desde el backoffice
 *
 * Emitir claves exige sesión de usuario, así que este caso de uso **no** está
 * expuesto en la API: una clave de la API no puede crear más claves de la
 * API. Es una decisión de seguridad, no una limitación técnica.
 */
final readonly class IssueApiKey
{
    public function __construct(
        private ApiKeyRepositoryInterface $apiKeys,
        private ApiKeyFactory $factory,
        private TenantContext $tenantContext,
        private Clock $clock,
    ) {
    }

    /**
     * @return array{key: ApiKey, secret: string}
     */
    public function __invoke(ApiKeyInput $input, ?Uuid $actorUserId = null): array
    {
        $organizationId = $this->tenantContext->requireOrganizationId();

        $name = trim($input->name);

        if ('' === $name) {
            throw new InvalidArgumentException('La clave necesita un nombre.');
        }

        if (mb_strlen($name) > 100) {
            throw new InvalidArgumentException('El nombre de la clave no puede pasar de 100 caracteres.');
        }

        $scopes = $this->normalizeScopes($input->scopes);

        $expiresAt = $this->expiry($input->expiresInDays);

        $generated = $this->factory->generate();

        $apiKey = new ApiKey(
            organizationId: $organizationId,
            name: $name,
            prefix: $generated['prefix'],
            secretHash: $generated['hash'],
            scopes: $scopes,
            expiresAt: $expiresAt,
            createdByUserId: $actorUserId,
        );

        $this->apiKeys->save($apiKey);

        return ['key' => $apiKey, 'secret' => $generated['secret']];
    }

    /**
     * Ámbitos finales de la clave.
     *
     * @param list<ApiScope> $scopes
     *
     * @return list<ApiScope>
     */
    private function normalizeScopes(array $scopes): array
    {
        if ([] === $scopes) {
            throw new InvalidArgumentException('La clave necesita al menos un ámbito.');
        }

        $final = [];

        foreach ($scopes as $scope) {
            $final[$scope->value] = $scope;

            // `write` arrastra `read`: se guarda explícito, no implícito.
            if (ApiScope::Write === $scope) {
                $final[ApiScope::Read->value] = ApiScope::Read;
            }
        }

        return array_values($final);
    }

    private function expiry(?int $days): ?DateTimeImmutable
    {
        if (null === $days) {
            return null;
        }

        if ($days < 1 || $days > 3650) {
            throw new InvalidArgumentException('La validez debe estar entre 1 día y 10 años.');
        }

        return $this->clock->now()->modify(sprintf('+%d days', $days));
    }
}
