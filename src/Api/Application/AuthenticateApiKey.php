<?php

declare(strict_types=1);

namespace App\Api\Application;

use App\Api\Domain\Entity\ApiKey;
use App\Api\Domain\Exception\InvalidApiKeyException;
use App\Api\Domain\Repository\ApiKeyRepositoryInterface;
use App\Shared\Application\Clock;
use DateInterval;
use DateTimeImmutable;

use function trim;

/**
 * Resuelve la clave que llega en la petición y comprueba que siga sirviendo.
 *
 * ## Los tres finales posibles
 *
 * Una clave puede no existir, haber caducado o haber sido revocada. Los tres
 * son un `401` para quien la usa, pero **no son lo mismo** para el operador:
 * por eso la excepción lleva el motivo y el panel lo enseña tal cual.
 *
 * Para quien la usa, el mensaje es siempre el mismo («la clave no es
 * válida»): distinguir «no existe» de «revocada» ayudaría a alguien que
 * está probando claves robadas.
 *
 * ## El último uso se anota, pero no en cada petición
 *
 * `lastUsedAt` se actualiza como mucho **una vez por hora**. Es lo bastante
 * para saber si una clave está viva y evita una escritura en base de datos
 * por petición, que es justo lo que se quiere evitar en una API usada por un
 * sincronizador que llama cada minuto.
 */
final readonly class AuthenticateApiKey
{
    /**
     * Cada cuánto se anota el uso de una clave.
     */
    private const USE_RECORD_INTERVAL = 'PT1H';

    public function __construct(
        private ApiKeyRepositoryInterface $apiKeys,
        private ApiKeyFactory $factory,
        private Clock $clock,
    ) {
    }

    public function __invoke(string $secret): ApiKey
    {
        $secret = trim($secret);

        if ('' === $secret) {
            throw InvalidApiKeyException::notFound();
        }

        $apiKey = $this->apiKeys->findBySecretHash($this->factory->hash($secret));

        if (null === $apiKey) {
            throw InvalidApiKeyException::notFound();
        }

        $now = $this->clock->now();

        if ($apiKey->isRevoked()) {
            throw InvalidApiKeyException::revoked();
        }

        if ($apiKey->isExpired($now)) {
            throw InvalidApiKeyException::expired();
        }

        $this->recordUse($apiKey, $now);

        return $apiKey;
    }

    private function recordUse(ApiKey $apiKey, \DateTimeImmutable $now): void
    {
        $lastUsedAt = $apiKey->getLastUsedAt();

        if (null !== $lastUsedAt && $lastUsedAt->add(new DateInterval(self::USE_RECORD_INTERVAL)) > $now) {
            return;
        }

        $apiKey->recordUse($now);

        $this->apiKeys->save($apiKey, false);
    }
}
