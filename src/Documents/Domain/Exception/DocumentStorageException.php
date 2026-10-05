<?php

declare(strict_types=1);

namespace App\Documents\Domain\Exception;

use RuntimeException;

/**
 * El almacenamiento de documentos ha fallado.
 *
 * Es una excepción de infraestructura, no de negocio: el usuario no puede
 * arreglarla, pero el sistema sí debe saber que el documento no está donde
 * dice estar.
 */
final class DocumentStorageException extends RuntimeException
{
}
