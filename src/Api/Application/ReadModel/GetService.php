<?php

declare(strict_types=1);

namespace App\Api\Application\ReadModel;

use App\Services\Domain\Repository\ServiceViewRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Un servicio concreto de la organización.
 *
 * Devuelve `null` cuando el identificador no existe **o** pertenece a otra
 * organización: para el integrador no hay diferencia entre «no existe» y «no
 * es tuyo». Es la misma respuesta que daría un `404` y evita confirmar la
 * existencia de recursos ajenos.
 */
final readonly class GetService
{
    public function __construct(private ServiceViewRepositoryInterface $services)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function __invoke(Uuid $id): ?array
    {
        $service = $this->services->findOne($id);

        if (null === $service) {
            return null;
        }

        return ResourceMapper::service($service);
    }
}
