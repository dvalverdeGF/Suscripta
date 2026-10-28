<?php

declare(strict_types=1);

namespace App\Api\UI\Form;

use App\Api\Domain\Enum\ApiScope;

/**
 * Datos del formulario de alta de una clave de API.
 *
 * ## Los ámbitos llegan como casillas
 *
 * El formulario tiene una casilla por ámbito, no un campo de texto: los
 * ámbitos son un conjunto cerrado y conocido, y una casilla deja claro que
 * `write` es más que `read` (además de incluirlo).
 *
 * La conversión de texto a enumeración la hace el propio `ChoiceType` (sus
 * opciones son los casos de `ApiScope`), así que aquí la propiedad ya es una
 * lista de `ApiScope` y el caso de uso no tiene que volver a interpretarla.
 * Un valor que no sea un ámbito conocido ni siquiera llega: el formulario lo
 * rechaza antes.
 */
final class ApiKeyFormData
{
    public ?string $name = null;

    /**
     * @var list<ApiScope>
     */
    public array $scopes = [ApiScope::Read];

    /**
     * Validez en días. `null` significa «no caduca».
     */
    public ?int $expiresInDays = null;
}
