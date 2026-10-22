<?php

declare(strict_types=1);

namespace App\Onboarding\Application\Dto;

/**
 * Un paso de la guía de bienvenida (Fase 17).
 *
 * ## Por qué los pasos se calculan y no se guardan
 *
 * El estado que importa es el del **producto**: ¿hay buzón? ¿hay servicios?
 * Eso se puede mirar en cualquier momento. Guardar un «paso 2 de 3» en la base
 * de datos es guardar algo que se queda viejo en cuanto el usuario hace la
 * acción desde otro sitio (o desde la API en el futuro). Por eso el paso se
 * deduce siempre de la realidad.
 *
 * El único estado que sí se guarda es «la guía se ha terminado», porque eso
 * es un hecho del pasado, no un estado del presente.
 */
final readonly class OnboardingStep
{
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public bool $done,
        /**
         * La ruta a la que lleva este paso, si lleva a alguna parte.
         */
        public ?string $path = null,
        /**
         * Texto del botón que hace el paso.
         */
        public ?string $action = null,
        /**
         * ¿Es el paso por el que se está pasando ahora?
         */
        public bool $current = false,
    ) {
    }
}
