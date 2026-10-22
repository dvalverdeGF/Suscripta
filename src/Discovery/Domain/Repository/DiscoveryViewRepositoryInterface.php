<?php

declare(strict_types=1);

namespace App\Discovery\Domain\Repository;

use App\Discovery\Domain\Dto\DiscoveryView;
use App\Discovery\Domain\Enum\DiscoveryStatus;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Shared\Application\Page;
use Symfony\Component\Uid\Uuid;

/**
 * Repositorio de **lectura** de propuestas (ARCHITECTURE.md §3).
 */
interface DiscoveryViewRepositoryInterface
{
    /**
     * @return Page<DiscoveryView>
     */
    public function findPage(
        ?DiscoveryStatus $status = null,
        ?DiscoveryType $type = null,
        int $page = 1,
        int $perPage = 25,
    ): Page;

    public function findOne(Uuid $id): ?DiscoveryView;
}
