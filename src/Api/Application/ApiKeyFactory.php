<?php

declare(strict_types=1);

namespace App\Api\Application;

use function base64_encode;
use function hash_hmac;
use function random_bytes;
use function rtrim;
use function strtr;
use function substr;

/**
 * Genera claves de API y calcula su huella.
 *
 * ## El formato de la clave
 *
 * `sk_` + 32 bytes aleatorios en base64url sin relleno. Los 32 bytes son
 * entropy real (256 bits): la clave no se puede adivinar ni con una lista de
 * palabras, y el prefijo `sk_` solo sirve para que un humano la reconozca en
 * un `.env` sin adivinar de qué es.
 *
 * ## Por qué HMAC y no un hash simple
 *
 * La huella se calcula con `hash_hmac('sha256', $clave, $pepper)`, con un
 * secreto **del servidor** que no está en la base de datos. Así, quien solo
 * tenga una copia de la base de datos no puede montar un diccionario de
 * candidatos: tendría que extraer el secreto del código en ejecución.
 *
 * ## Por qué no `bcrypt`
 *
 * `bcrypt` está pensado para contraseñas, que el usuario elige y tienen poca
 * entropía. Aquí la clave tiene 256 bits: no hay diccionario que atacar y,
 * sobre todo, **cada petición de la API verifica una clave**. Un `bcrypt` de
 * 100 ms convertiría la API en un servicio de Pony Express. Con HMAC-SHA-256
 * la comprobación es de microsegundos.
 */
final readonly class ApiKeyFactory
{
    /** Prefijo visible de toda clave de Suscripta. */
    public const PREFIX = 'sk_';

    /** Cuántos caracteres de la clave se guardan en claro para identificarla. */
    public const VISIBLE_LENGTH = 12;

    public function __construct(private string $pepper)
    {
    }

    /**
     * Genera una clave nueva.
     *
     * @return array{secret: string, prefix: string, hash: string}
     */
    public function generate(): array
    {
        $secret = self::PREFIX.self::base64Url(random_bytes(32));

        return [
            'secret' => $secret,
            'prefix' => substr($secret, 0, self::VISIBLE_LENGTH),
            'hash' => $this->hash($secret),
        ];
    }

    /**
     * Huella de una clave.
     */
    public function hash(string $secret): string
    {
        return hash_hmac('sha256', $secret, $this->pepper);
    }

    /**
     * Base64url sin relleno: los caracteres `+` y `/` se rompen al copiar y
     * pegar en una cabecera, y el `=` final es ruido.
     */
    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
