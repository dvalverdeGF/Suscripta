<?php

declare(strict_types=1);

namespace App\Tests\Support\Mailbox;

use App\Mailbox\Application\Imap\Discovery\SrvRecord;
use App\Mailbox\Application\Imap\Discovery\SrvResolverInterface;

/**
 * Resolutor de SRV que devuelve lo que el test le dice.
 *
 * El DNS real no se puede usar en una tanda de tests: el mismo nombre resuelve
 * distinto en cada máquina, o no resuelve si no hay red, y el test empezaría a
 * fallar sin que haya cambiado nada del código.
 */
final class FakeSrvResolver implements SrvResolverInterface
{
    /**
     * Registros por nombre completo, p. ej. `_imaps._tcp.ejemplo.com`.
     *
     * @var array<string, SrvRecord>
     */
    public array $records = [];

    /** @var list<string> */
    public array $queries = [];

    public function resolve(string $name): ?SrvRecord
    {
        $this->queries[] = $name;

        return $this->records[$name] ?? null;
    }

    public function reset(): void
    {
        $this->records = [];
        $this->queries = [];
    }
}
