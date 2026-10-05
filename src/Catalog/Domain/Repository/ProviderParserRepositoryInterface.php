<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Entity\ProviderParser;
use Symfony\Component\Uid\Uuid;

interface ProviderParserRepositoryInterface
{
    public function find(Uuid $id): ?ProviderParser;

    /**
     * Parsers activos de un proveedor, del más nuevo al más antiguo.
     *
     * Se devuelven todos y no solo el último porque un proveedor puede cambiar
     * de plantilla sin dejar de emitir facturas antiguas: el pipeline prueba en
     * orden y se queda con el primero que reconozca el documento.
     *
     * @return list<ProviderParser>
     */
    public function findEnabledForProvider(Uuid $providerId): array;

    /**
     * @return list<ProviderParser>
     */
    public function findForProvider(Uuid $providerId): array;

    public function save(ProviderParser $parser, bool $flush = true): void;
}
