<?php

declare(strict_types=1);

namespace App\Api\UI\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Filtros del listado de próximos cobros.
 */
final readonly class ChargesQuery
{
    public function __construct(
        public int $days = 30,
        public int $page = 1,
        #[SerializedName('per_page')]
        public int $perPage = 25,
    ) {
    }
}
