<?php

declare(strict_types=1);

namespace App\Api\UI\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Filtros del listado de propuestas de descubrimiento.
 */
final readonly class ProposalsQuery
{
    public function __construct(
        public ?string $status = null,
        public ?string $type = null,
        public int $page = 1,
        #[SerializedName('per_page')]
        public int $perPage = 25,
    ) {
    }
}
