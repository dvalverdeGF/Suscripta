<?php

declare(strict_types=1);

namespace App\Webhooks\Infrastructure\Http;

use App\Webhooks\Application\Port\HostResolverInterface;

use const DNS_AAAA;

use function dns_get_record;

use const FILTER_VALIDATE_IP;

use function filter_var;
use function gethostbynamel;
use function is_array;
use function is_string;

/**
 * Resolución DNS real, para la guarda anti-SSRF (Fase 20).
 *
 * Pide A y AAAA por separado y devuelve las dos familias: comprobar solo IPv4
 * dejaría pasar un host que responde `::1`, que es exactamente el atajo que
 * busca quien intenta alcanzar la red interna.
 *
 * Una dirección IP literal se devuelve tal cual, sin consultar al DNS. Es el
 * caso de un suscriptor que apunta a `https://10.0.0.5/hooks`: no hay nada que
 * resolver, y hay que rechazarla igual.
 */
final readonly class DnsHostResolver implements HostResolverInterface
{
    public function resolve(string $host): array
    {
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $addresses = [];

        $ipv4 = gethostbynamel($host);

        if (is_array($ipv4)) {
            foreach ($ipv4 as $address) {
                $addresses[] = $address;
            }
        }

        $records = @dns_get_record($host, DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                $address = $record['ipv6'] ?? null;

                if (is_string($address)) {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
