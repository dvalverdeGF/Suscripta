<?php

declare(strict_types=1);

namespace App\Services\Application\Privacy;

use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Privacy\OrganizationDataRemoverInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Borra los servicios de una organización.
 *
 * El borrado va servicio a servicio porque `Service` arrastra en cascada sus
 * precios y sus eventos: un borrado masivo dejaría esas filas sin dueño.
 *
 * Se ejecuta **después** de descubrimientos, documentos y alertas, que
 * apuntan a los servicios: así ninguna de esas filas queda apuntando a un
 * servicio que ya no existe.
 */
final readonly class ServicesDataRemover implements OrganizationDataRemoverInterface
{
    public function __construct(
        private ServiceRepositoryInterface $services,
    ) {
    }

    public function module(): string
    {
        return 'services';
    }

    /**
     * Después de descubrimientos, alertas y documentos: los tres apuntan a
     * los servicios, así que tienen que irse antes.
     */
    public function priority(): int
    {
        return 45;
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        return $this->services->removeForOrganization($organizationId);
    }
}
