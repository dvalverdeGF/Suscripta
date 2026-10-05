<?php

declare(strict_types=1);

namespace App\Mailbox\Application;

/**
 * Cifra y descifra las credenciales de un buzón (SECURITY.md §3).
 *
 * El dominio nunca ve una contraseña en claro: guarda un valor opaco y solo lo
 * abre en el momento de abrir la conexión. La implementación concreta no se
 * filtra hacia el dominio, para poder cambiar el algoritmo sin tocar las
 * entidades.
 */
interface CredentialCipherInterface
{
    /**
     * Devuelve un valor opaco y autocontenido (incluye su propio *nonce* y su
     * versión de algoritmo), listo para guardar en base de datos.
     */
    public function encrypt(string $plaintext): string;

    /**
     * @throws \App\Shared\Domain\Exception\InvalidArgumentException si el valor
     *                                                               no se puede descifrar (clave rotada, dato corrupto o manipulado)
     */
    public function decrypt(string $payload): string;
}
