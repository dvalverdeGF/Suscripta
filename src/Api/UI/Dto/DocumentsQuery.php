<?php

declare(strict_types=1);

namespace App\Api\UI\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Filtros del listado de documentos.
 */
final readonly class DocumentsQuery
{
    public function __construct(
        public ?string $type = null,
        #[SerializedName('service_id')]
        public ?string $serviceId = null,
        #[SerializedName('include_deleted')]
        public bool $includeDeleted = false,
        public int $page = 1,
        #[SerializedName('per_page')]
        public int $perPage = 25,
    ) {
    }
}
