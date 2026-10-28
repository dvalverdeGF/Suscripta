<?php

declare(strict_types=1);

namespace App\Webhooks\UI\Form;

use App\Webhooks\Domain\Enum\WebhookEvent;

/**
 * Datos del formulario de un suscriptor de webhooks.
 *
 * ## Los eventos llegan como casillas
 *
 * Igual que los ámbitos de una clave de API: el conjunto es cerrado y pequeño,
 * y una casilla deja ver de un vistazo a qué está suscrito el receptor. Un
 * `<select multiple>` escondería la mitad de los eventos detrás de un clic.
 *
 * ## `active` es una casilla aparte
 *
 * Pausar un suscriptor no es lo mismo que borrarlo: el historial de entregas
 * se conserva y se puede reanudar. Por eso el formulario de edición lo ofrece
 * como una casilla y no como un botón de borrar.
 */
final class WebhookFormData
{
    public ?string $name = null;

    public ?string $url = null;

    /**
     * @var list<WebhookEvent>
     */
    public array $events = [];

    public bool $active = true;
}
