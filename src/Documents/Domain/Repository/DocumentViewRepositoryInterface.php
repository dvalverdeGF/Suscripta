<?php

declare(strict_types=1);

namespace App\Documents\Domain\Repository;

use App\Documents\Domain\Dto\DocumentView;
use App\Documents\Domain\Enum\DocumentType;
use App\Shared\Application\Page;
use Symfony\Component\Uid\Uuid;

/**
 * Repositorio de **lectura** de documentos (ARCHITECTURE.md §3).
 */
interface DocumentViewRepositoryInterface
{
    /**
     * @return Page<DocumentView>
     */
    public function findPage(
        ?DocumentType $type = null,
        ?Uuid $serviceId = null,
        ?bool $includeDeleted = false,
        int $page = 1,
        int $perPage = 25,
    ): Page;

    public function findOne(Uuid $id): ?DocumentView;
}
