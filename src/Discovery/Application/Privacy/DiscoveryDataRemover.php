<?php

declare(strict_types=1);

namespace App\Discovery\Application\Privacy;

use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Shared\Application\Privacy\OrganizationDataRemoverInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Borra los descubrimientos de una organización.
 *
 * Las **evidencias** (el correo o documento que motivó el descubrimiento) se
 * borran con su descubrimiento: sin el descubrimiento, la evidencia no tiene
 * a qué referirse y solo ocuparía sitio.
 */
final readonly class DiscoveryDataRemover implements OrganizationDataRemoverInterface
{
    public function __construct(
        private DiscoveryRepositoryInterface $discoveries,
    ) {
    }

    public function module(): string
    {
        return 'discovery';
    }

    /**
     * Los descubrimientos apuntan a servicios y documentos: se van antes que
     * ellos.
     */
    public function priority(): int
    {
        return 20;
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        return $this->discoveries->removeForOrganization($organizationId);
    }
}
