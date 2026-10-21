<?php

declare(strict_types=1);

namespace App\Ai\Domain\Enum;

/**
 * Para qué se usa una llamada a la IA (ARCHITECTURE.md §13.8).
 *
 * El panel de integraciones permite **elegir un proveedor distinto para cada
 * necesidad**: no es lo mismo un modelo que extrae importes de una factura que
 * uno que redacta un resumen para el usuario. Sin esta distinción, «el proveedor
 * de IA» es un único ajuste para tareas que no se parecen en nada, y cambiar el
 * modelo para una de ellas obliga a cambiarlo para todas.
 *
 * Cada necesidad tiene su propio **proveedor por defecto**, pero el operador
 * puede configurarlo desde el panel.
 */
enum AiPurpose: string
{
    case EXTRACTION = 'extraction';
    case ADVANCED_EXTRACTION = 'advanced_extraction';
    case SUMMARIZATION = 'summarization';
    case CLASSIFICATION = 'classification';

    public function label(): string
    {
        return match ($this) {
            self::EXTRACTION => 'Extracción de documentos',
            self::ADVANCED_EXTRACTION => 'Extracción avanzada',
            self::SUMMARIZATION => 'Resúmenes',
            self::CLASSIFICATION => 'Clasificación',
        };
    }

    /**
     * Qué se hace con esta necesidad, en una línea.
     *
     * Va en la pantalla de elección: una etiqueta («Extracción de documentos»)
     * dice **qué** es pero no **para qué sirve**, y el operador que no conoce
     * la aplicación necesita las dos cosas para elegir bien.
     */
    public function description(): string
    {
        return match ($this) {
            self::EXTRACTION => 'Leer facturas y recibos que las reglas no consiguen entender.',
            self::ADVANCED_EXTRACTION => 'Segunda pasada, más cara, para los casos que la primera deja sin resolver.',
            self::SUMMARIZATION => 'Resumir el contenido de un correo o de un documento.',
            self::CLASSIFICATION => 'Decidir de qué tipo es un documento o de qué categoría es un correo.',
        };
    }

    /**
     * Si esta necesidad admite un proveedor **avanzado**.
     *
     * La extracción normal va con un modelo económico: es la que se ejecuta en
     * cada documento que las reglas no leen, y su volumen es alto. El resumen y
     * la clasificación son esporádicos, así que pueden permitirse un modelo
     * caro sin que la factura duela.
     */
    public function allowsAdvancedProvider(): bool
    {
        return match ($this) {
            self::EXTRACTION, self::ADVANCED_EXTRACTION, self::SUMMARIZATION, self::CLASSIFICATION => true,
        };
    }

    /**
     * Todas las necesidades, para el panel de integraciones.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
