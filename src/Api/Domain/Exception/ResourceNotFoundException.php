<?php

declare(strict_types=1);

namespace App\Api\Domain\Exception;

use RuntimeException;

use function sprintf;

/**
 * El recurso solicitado no existe (o no es de esta organización).
 *
 * ## Por qué existe una excepción propia
 *
 * «No existe» y «no es tuyo» son la **misma** respuesta para quien integra:
 * en ambos casos el recurso no está. Separarlas filtraría información sobre
 * lo que hay en otras organizaciones. Por eso esta excepción se usa tanto
 * para un id que no existe como para uno que pertenece a otro cliente, y en
 * los dos casos la respuesta es un `404` sin más detalle.
 */
final class ResourceNotFoundException extends RuntimeException
{
    public static function of(string $what): self
    {
        return new self(sprintf('No se ha encontrado %s.', $what));
    }
}
