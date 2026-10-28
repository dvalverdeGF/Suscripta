<?php

declare(strict_types=1);

namespace App\Tests\Support\Webhooks;

use App\Webhooks\Application\Port\HostResolverInterface;

use function strtolower;

/**
 * Resolutor DNS controlable desde los tests.
 *
 * La guarda anti-SSRF decide si una URL de webhook es aceptable mirando a qué
 * direcciones resuelve su host, y el DNS de verdad no se puede guionizar: el
 * mismo nombre puede contestar una cosa distinta en la máquina de cada uno, o
 * no contestar nada si no hay red. Este doble devuelve exactamente lo que el
 * test le dice, y lo que no se le dice no resuelve.
 */
final class ControllableHostResolver implements HostResolverInterface
{
    /**
     * @var array<string, list<string>>
     */
    private array $addresses = [];

    /**
     * @param list<string> $addresses
     */
    public function willResolve(string $host, array $addresses): void
    {
        $this->addresses[strtolower($host)] = $addresses;
    }

    public function forget(string $host): void
    {
        unset($this->addresses[strtolower($host)]);
    }

    public function resolve(string $host): array
    {
        return $this->addresses[strtolower($host)] ?? [];
    }
}
