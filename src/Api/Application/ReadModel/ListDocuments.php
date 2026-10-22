<?php

declare(strict_types=1);

namespace App\Api\Application\ReadModel;

use App\Api\Application\ApiCollection;
use App\Documents\Domain\Enum\DocumentType;
use App\Documents\Domain\Repository\DocumentViewRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Listado de documentos de la organización.
 */
final readonly class ListDocuments
{
    public function __construct(private DocumentViewRepositoryInterface $documents)
    {
    }

    public function __invoke(
        ?DocumentType $type = null,
        ?Uuid $serviceId = null,
        bool $includeDeleted = false,
        int $page = 1,
        int $perPage = 25,
    ): ApiCollection {
        $result = $this->documents->findPage($type, $serviceId, $includeDeleted, $page, $perPage);

        $items = [];
        foreach ($result->items as $document) {
            $items[] = ResourceMapper::document($document);
        }

        return ApiCollection::of($items, $result->pagination);
    }
}
