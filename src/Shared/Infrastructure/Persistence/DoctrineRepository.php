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
}
