<?php

declare(strict_types=1);

namespace App\Privacy\Application;

use App\Privacy\Application\Dto\PurgeReport;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\Privacy\RetentionPurgerInterface;
use App\Shared\Domain\Enum\AuditAction;

/**
 * Aplica la política de retención: purga lo que ha caducado.
 *
 * Las políticas son por categoría de dato y no por módulo, porque lo que
 * caduca es la **naturaleza** del dato: el correo es evidencia temporal, los
 * servicios son el producto (SECURITY.md §6). Cada purificador declara a qué
 * categoría pertenece y el informe dice qué se ha borrado y por qué.
 */
final readonly class PurgeExpiredData
{
    /**
     * @param iterable<RetentionPurgerInterface> $purgers
     */
    public function __construct(
        private iterable $purgers,
        private AuditLoggerInterface $auditLogger,
        private Clock $clock,
    ) {
    }

    public function __invoke(): PurgeReport
    {
        $now = $this->clock->now();
        $removed = [];

        foreach ($this->purgers as $purger) {
            $removed[$purger->category()] = $purger->purge($now);
        }

        ksort($removed);

        $report = new PurgeReport($removed);

        $this->auditLogger->log(
            action: AuditAction::DATA_PURGED,
            targetType: 'retention',
            metadata: [
                'scope' => 'retention',
                'categories' => implode(',', array_keys($removed)),
                'removed' => $report->total(),
            ],
        );

        return $report;
    }
}
