<?php

declare(strict_types=1);

namespace App\Tests\Support\Services;

use App\Services\Domain\Entity\Service;
use App\Services\Domain\Entity\ServiceEvent;

use function sprintf;

/**
 * `Service::getEvents()` devuelve una `Collection`, cuyo acceso por índice es
 * anulable. Este ayudante convierte ese acceso en una aserción explícita para
 * que los tests puedan leer el evento sin ruido de tipos.
 */
trait ServiceEventAssertions
{
    private static function eventAt(Service $service, int $index): ServiceEvent
    {
        $event = $service->getEvents()->get($index);

        self::assertInstanceOf(ServiceEvent::class, $event, sprintf('No hay evento en la posición %d.', $index));

        return $event;
    }
}
