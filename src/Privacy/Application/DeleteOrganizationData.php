<?php

declare(strict_types=1);

namespace App\Privacy\Application;

use App\Privacy\Application\Dto\RemovalReport;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Privacy\OrganizationDataRemoverInterface;
use App\Shared\Domain\Enum\AuditAction;
use Symfony\Component\Uid\Uuid;

/**
 * Borra todos los datos de una organización (derecho de supresión).
 *
 * Cada módulo borra lo suyo mediante `OrganizationDataRemoverInterface`. Este
 * caso de uso solo coordina y **da fe**: recoge lo que cada módulo ha
 * eliminado y lo deja escrito en la auditoría, que es append-only, así que
 * la constancia del borrado sobrevive al borrado.
 */
final readonly class DeleteOrganizationData
{
    /**
     * @param iterable<OrganizationDataRemoverInterface> $removers
     */
    public function __construct(
        private iterable $removers,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(Uuid $organizationId, ?Uuid $actorUserId = null): RemovalReport
    {
        $removed = [];

        foreach ($this->orderedRemovers() as $remover) {
            $removed[$remover->module()] = $remover->removeForOrganization($organizationId);
        }

        ksort($removed);

        $report = new RemovalReport($removed);

        $this->auditLogger->log(
            action: AuditAction::DATA_PURGED,
            targetType: 'organization',
            targetId: $organizationId->toRfc4122(),
            metadata: [
                'scope' => 'organization',
                'modules' => implode(',', array_keys($removed)),
                'removed' => $report->total(),
            ],
            organizationId: $organizationId,
            actorUserId: $actorUserId,
        );

        return $report;
    }

    /**
     * Los removedores ordenados por prioridad: el que borra primero, el
     * primero.
     *
     * @return list<OrganizationDataRemoverInterface>
     */
    private function orderedRemovers(): array
    {
        $removers = [];

        foreach ($this->removers as $remover) {
            $removers[] = $remover;
        }

        usort(
            $removers,
            static fn (OrganizationDataRemoverInterface $a, OrganizationDataRemoverInterface $b): int => $a->priority() <=> $b->priority(),
        );

        return $removers;
    }
}
