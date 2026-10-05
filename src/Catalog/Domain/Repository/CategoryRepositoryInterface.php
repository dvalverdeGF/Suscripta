<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Entity\Category;
use Symfony\Component\Uid\Uuid;

interface CategoryRepositoryInterface
{
    public function find(Uuid $id): ?Category;

    /**
     * @return list<Category>
     */
    public function findVisibleForOrganization(Uuid $organizationId): array;

    /**
     * @return array<string, Category> indexado por id en forma de cadena
     */
    public function findVisibleIndexedById(Uuid $organizationId): array;

    /**
     * Busca una categoría del catálogo global (organization_id IS NULL).
     * Lo usa el sembrador del catálogo, que debe ser idempotente.
     */
    public function findGlobalBySlug(string $slug): ?Category;

    public function save(Category $category, bool $flush = true): void;
}
