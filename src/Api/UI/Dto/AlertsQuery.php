<?php

declare(strict_types=1);

namespace App\Api\UI\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Filtros del listado de alertas.
 */
final readonly class AlertsQuery
{
    public function __construct(
        public ?string $status = null,
        public ?string $severity = null,
        public ?string $search = null,
        public ?string $sort = null,
        public int $page = 1,
        #[SerializedName('per_page')]
        public int $perPage = 25,
    ) {
    }
}
