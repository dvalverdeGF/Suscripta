<?php

declare(strict_types=1);

namespace App\Documents\Application\Entitlements;

use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Shared\Application\Entitlements\Capability;
use App\Shared\Application\Entitlements\EntitlementUsageProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Cuántos documentos guarda la organización activa (Fase 17).
 *
 * Los documentos marcados como borrados no cuentan: siguen en el
 * almacenamiento durante el periodo de gracia (Fase 16) para que el usuario
 * pueda arrepentirse, pero ya no son documentos de la organización.
 *
 * El atributo `#[AsTaggedItem]` dice bajo qué clave se guarda este proveedor
 * en el localizador de cuotas (D-70).
 */
#[AsTaggedItem(Capability::DOCUMENTS)]
final readonly class DocumentUsageProvider implements EntitlementUsageProviderInterface
{
    public function __construct(
        private DocumentRepositoryInterface $documents,
    ) {
    }

    public function capability(): string
    {
        return Capability::DOCUMENTS;
    }

    public function usage(): int
    {
        return $this->documents->countForOrganization();
    }
}
