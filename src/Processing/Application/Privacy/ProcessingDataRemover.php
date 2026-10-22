<?php

declare(strict_types=1);

namespace App\Processing\Application\Privacy;

use App\Processing\Domain\Repository\ExtractionCacheRepositoryInterface;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use App\Shared\Application\Privacy\OrganizationDataRemoverInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Borra lo aprendido del correo: reglas y caché de extracción.
 *
 * Es el módulo de procesamiento quien sabe qué es conocimiento y qué es caché,
 * así que es quien los borra. Los demás módulos no tocan estas tablas.
 */
final readonly class ProcessingDataRemover implements OrganizationDataRemoverInterface
{
    public function __construct(
        private MailboxKnowledgeRepositoryInterface $knowledge,
        private ExtractionCacheRepositoryInterface $cache,
    ) {
    }

    public function module(): string
    {
        return 'processing';
    }

    /**
     * El conocimiento aprendido y la caché no dependen de nada más: se van
     * primero.
     */
    public function priority(): int
    {
        return 10;
    }

    public function removeForOrganization(Uuid $organizationId): int
    {
        $knowledgeRemoved = $this->knowledge->removeForOrganization($organizationId);
        $cacheRemoved = $this->cache->removeForOrganization($organizationId);

        return $knowledgeRemoved + $cacheRemoved;
    }
}
