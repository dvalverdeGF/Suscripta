<?php

declare(strict_types=1);

namespace App\Services\Application;

use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Cambia el precio vigente de un servicio.
 *
 * Cierra la fila anterior e inserta una nueva. Si el importe no cambia, no hace
 * nada: no queremos historial lleno de filas idénticas.
 */
final readonly class ChangeServicePrice
{
    public function __construct(
        private ServiceRepositoryInterface $services,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(
        Service $service,
        Money $amount,
        DateTimeImmutable $validFrom,
        ?string $note = null,
        ?Uuid $actorUserId = null,
    ): bool {
        $previous = $service->getCurrentAmount();

        $changed = $service->changePrice(
            amount: $amount,
            validFrom: $validFrom,
            note: $note,
        );

        if (!$changed) {
            return false;
        }

        $service->recordEvent(ServiceEventType::PRICE_CHANGED, $actorUserId, [
            'from' => $previous?->format(false),
            'to' => $amount->format(false),
            'currency' => $amount->currency->value,
            'validFrom' => $validFrom->format('Y-m-d'),
        ]);

        $this->services->save($service);

        $this->auditLogger->log(
            action: AuditAction::SERVICE_PRICE_CHANGED,
            targetType: 'service',
            targetId: $service->getId()->toRfc4122(),
            metadata: [
                'from' => $previous?->format(false),
                'to' => $amount->format(false),
            ],
        );

        return true;
    }
}
