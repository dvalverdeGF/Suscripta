<?php

declare(strict_types=1);

namespace App\Api\Domain\Enum;

/**
 * Ámbitos de una clave de API.
 *
 * ## Por qué hay ámbitos y no un interruptor
 *
 * Una clave que puede escribir puede **borrar** datos, y una clave que solo
 * lee no puede. Si todas las claves pueden hacerlo todo, basta con que se
 * filtre una para tener acceso completo a la cuenta, y el integrador tiene
 * una sola credencial para todo.
 *
 * Con ámbitos, el integrador que solo necesita leer el inventario recibe una
 * clave de lectura: si se filtra, el daño se limita a una fuga de datos, no a
 * una manipulación de la facturación.
 *
 * ## `write` incluye `read`
 *
 * Una clave de escritura puede leer sin discusión: quien puede corregir un
 * servicio tiene que poder verlo primero. Lo contrario —una clave que escribe
 * pero no lee— sería una rareza que solo daría problemas. La regla se aplica
 * al emitir la clave (ver `IssueApiKey`), no al comprobar: así el conjunto de
 * ámbitos guardado es siempre explícito y se ve en el panel.
 */
enum ApiScope: string
{
    case Read = 'read';
    case Write = 'write';

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Lectura',
            self::Write => 'Escritura',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Read => 'Consultar el inventario, los documentos y las métricas.',
            self::Write => 'Consultar y además crear servicios, subir documentos y confirmar propuestas.',
        };
    }

    /**
     * ¿Este ámbito cubre al otro?
     *
     * `write` cubre `read`; `read` no cubre `write`.
     */
    public function covers(self $scope): bool
    {
        return $this === $scope || (self::Write === $this && self::Read === $scope);
    }
}
