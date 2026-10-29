<?php

declare(strict_types=1);

namespace App\Mailbox\Infrastructure\Imap\Discovery;

use App\Mailbox\Application\Imap\Discovery\SrvRecord;
use App\Mailbox\Application\Imap\Discovery\SrvResolverInterface;

use function dns_get_record;

use const DNS_SRV;

use function is_array;
use function is_int;
use function is_string;

/**
 * Resuelve registros SRV con el DNS del sistema.
 *
 * Cuando hay varios registros, la RFC 2782 manda usar el de **menor prioridad**
 * y, entre los de la misma prioridad, repartir por peso. Aquí se toma el de
 * menor prioridad y mayor peso: la aplicación abre una sola conexión, así que
 * repartir carga no tiene sentido, pero elegir el que el proveedor marcó como
 * preferente, sí.
 */
final readonly class DnsSrvResolver implements SrvResolverInterface
{
    public function resolve(string $name): ?SrvRecord
    {
        // `dns_get_record()` avisa por `E_WARNING` cuando el dominio no existe o
        // no tiene registros SRV. Eso no es un error de la aplicación: es la
        // respuesta «no hay» que se espera en la mayoría de los dominios.
        $records = @dns_get_record($name, DNS_SRV);

        if (!is_array($records) || [] === $records) {
            return null;
        }

        $best = null;

        foreach ($records as $record) {
            $target = $record['target'] ?? null;
            $port = $record['port'] ?? null;

            if (!is_string($target) || '' === $target || !is_int($port)) {
                continue;
            }

            $priority = is_int($record['pri'] ?? null) ? $record['pri'] : 0;
            $weight = is_int($record['weight'] ?? null) ? $record['weight'] : 0;

            if (null === $best
                || $priority < $best['priority']
                || ($priority === $best['priority'] && $weight > $best['weight'])) {
                $best = ['priority' => $priority, 'weight' => $weight, 'record' => new SrvRecord($target, $port)];
            }
        }

        return $best['record'] ?? null;
    }
}
