<?php

declare(strict_types=1);

namespace App\Webhooks\Application\Port;

/**
 * Traduce un nombre de host a las direcciones IP a las que resuelve (Fase 20).
 *
 * Existe como puerto para que la guarda anti-SSRF se pueda probar sin red: un
 * test que dependa del DNS real es un test que falla el día que cambia un
 * registro, y con este puerto las direcciones privadas se prueban todas sin
 * salir del proceso.
 */
interface HostResolverInterface
{
    /**
     * @return list<string> Direcciones IPv4 e IPv6. Vacía si no resuelve.
     */
    public function resolve(string $host): array;
}
