<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Base de los repositorios Doctrine.
 *
 * No expone el EntityManager hacia el dominio: los repositorios concretos
 * declaran métodos con lenguaje de negocio (`findActiveByOrganization()`,
 * `findByContentHash()`), no consultas genéricas.
 */
abstract class DoctrineRepository
{
    public function __construct(protected readonly EntityManagerInterface $entityManager)
    {
    }

    protected function persist(object $entity, bool $flush = false): void
    {
        $this->entityManager->persist($entity);

        if ($flush) {
            $this->entityManager->flush();
        }
    }

    protected function delete(object $entity, bool $flush = false): void
    {
        $this->entityManager->remove($entity);

        if ($flush) {
            $this->entityManager->flush();
        }
    }

    protected function flush(): void
    {
        $this->entityManager->flush();
    }

    /**
     * Normaliza el resultado de una consulta de una sola columna en una lista
     * de identificadores utilizable en cláusulas `IN (:ids)`.
     *
     * Doctrine devuelve los identificadores ya convertidos al tipo de la entidad
     * (por ejemplo, instancias de `Uuid`), de ahí la normalización a texto.
     *
     * @param array<mixed> $rows
     *
     * @return list<string>
     */
    protected function scalarIds(array $rows): array
    {
        return array_values(array_map(
            static fn (mixed $id): string => (string) $id,
            $rows
        ));
    }
}
