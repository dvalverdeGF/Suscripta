<?php

declare(strict_types=1);

namespace App\Api\UI\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Filtros del listado de servicios.
 *
 * ## Los filtros llegan como texto
 *
 * Los estados y los identificadores llegan como cadenas y los convierte el
 * caso de uso. Si el deserializador recibiera el enum directamente, un estado
 * inexistente produciría un error de sintaxis («este valor no es del tipo
 * esperado») en vez de algo útil («el estado «archivado» no existe»).
 */
final readonly class ServicesQuery
{
    public function __construct(
        public ?string $status = null,
        #[SerializedName('category_id')]
        public ?string $categoryId = null,
        #[SerializedName('provider_id')]
        public ?string $providerId = null,
        public ?string $search = null,
        public ?string $sort = null,
        public int $page = 1,
        #[SerializedName('per_page')]
        public int $perPage = 25,
    ) {
    }
}
