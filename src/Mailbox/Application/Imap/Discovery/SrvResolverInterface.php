<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Imap\Discovery;

/**
 * Consulta de registros SRV (RFC 2782).
 *
 * La autoconfiguración de correo (RFC 6186) publica el servidor IMAP en
 * `_imaps._tcp.<dominio>`. Es el método más fiable que existe sin pedirle nada
 * al usuario y sin descargarse un XML de un servidor ajeno, así que es el
 * primero que se prueba.
 *
 * Es un puerto y no una llamada directa a `dns_get_record()` para poder probar
 * la detección sin red: un test que depende del DNS real falla el día que el
 * proveedor cambia un registro.
 */
interface SrvResolverInterface
{
    /**
     * @param string $name nombre completo del registro, p. ej. `_imaps._tcp.ejemplo.com`
     *
     * @return SrvRecord|null el de menor prioridad, o `null` si no hay
     */
    public function resolve(string $name): ?SrvRecord;
}
