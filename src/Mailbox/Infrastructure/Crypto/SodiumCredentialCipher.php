<?php

declare(strict_types=1);

namespace App\Mailbox\Infrastructure\Crypto;

use App\Mailbox\Application\CredentialCipherInterface;
use App\Shared\Domain\Exception\InvalidArgumentException;

use function base64_decode;
use function base64_encode;

use SensitiveParameter;

use function sodium_crypto_generichash;
use function sodium_crypto_secretbox;

use const SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
use const SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

use function sodium_crypto_secretbox_open;
use function sprintf;
use function strlen;
use function substr;

/**
 * Cifrado autenticado con libsodium (`crypto_secretbox`).
 *
 * La clave se deriva de `APP_SECRET` con BLAKE2b, de forma que rotar el secreto
 * de la aplicación invalida las credenciales guardadas: es el comportamiento
 * correcto, porque un secreto filtrado no debe seguir abriendo buzones ajenos.
 *
 * El formato guardado es `v1:<base64(nonce || caja)>`. El prefijo de versión
 * permite cambiar de algoritmo más adelante sin adivinar qué hay en cada fila.
 *
 * `crypto_secretbox` es cifrado **autenticado**: si alguien manipula la fila en
 * base de datos, el descifrado falla en lugar de devolver basura.
 */
final class SodiumCredentialCipher implements CredentialCipherInterface
{
    private const string VERSION = 'v1';
    private const int NONCE_BYTES = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    private const int KEY_BYTES = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

    private readonly string $key;

    public function __construct(#[SensitiveParameter] string $appSecret)
    {
        if ('' === $appSecret) {
            throw new InvalidArgumentException('No se puede cifrar credenciales sin un secreto de aplicación.');
        }

        $this->key = sodium_crypto_generichash($appSecret, '', self::KEY_BYTES);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $box = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return self::VERSION.':'.base64_encode($nonce.$box);
    }

    public function decrypt(string $payload): string
    {
        $separator = strpos($payload, ':');

        if (false === $separator || self::VERSION !== substr($payload, 0, $separator)) {
            throw new InvalidArgumentException('El formato de las credenciales guardadas no se reconoce.');
        }

        $raw = base64_decode(substr($payload, $separator + 1), true);

        if (false === $raw || strlen($raw) <= self::NONCE_BYTES) {
            throw new InvalidArgumentException('Las credenciales guardadas están corruptas.');
        }

        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $box = substr($raw, self::NONCE_BYTES);
        $plaintext = sodium_crypto_secretbox_open($box, $nonce, $this->key);

        if (false === $plaintext) {
            throw new InvalidArgumentException(sprintf('No se han podido descifrar las credenciales. ¿Ha cambiado %s?', 'APP_SECRET'));
        }

        return $plaintext;
    }
}
