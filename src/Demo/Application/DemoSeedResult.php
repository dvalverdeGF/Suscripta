<?php

declare(strict_types=1);

namespace App\Demo\Application;

/**
 * Lo que ha hecho la siembra, para poder contarlo.
 *
 * Es un DTO y no un `array` porque el comando lo imprime: con nombres de
 * campo, el mensaje no puede quedar descolocado por un `array`.
 */
final readonly class DemoSeedResult
{
    public function __construct(
        public string $email,
        public string $password,
        public bool $userCreated,
        public string $organizationName,
        public int $servicesCreated,
        public int $servicesSkipped,
        public int $proposals,
        public int $alertsCreated,
        public int $aiCalls,
    ) {
    }
}
