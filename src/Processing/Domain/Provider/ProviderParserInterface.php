<?php

declare(strict_types=1);

namespace App\Processing\Domain\Provider;

/**
 * Un parser de proveedor (ARCHITECTURE.md §13.7).
 *
 * Hay dos familias y ambas implementan este contrato:
 *
 * - **Parsers de código** (`key` = `ovh`, `github`…): saben leer la plantilla
 *   exacta de un proveedor y son los más fiables.
 * - **Parser declarativo** (`key` = `declarative`): lee la configuración
 *   guardada en `ProviderParser.config`, así que un proveedor nuevo se puede
 *   enseñar sin desplegar código.
 *
 * El contrato es el mismo para que el pipeline no tenga que distinguirlas: una
 * vez que un proveedor es conocido, **no se vuelve a pagar IA por sus facturas
 * futuras**.
 */
interface ProviderParserInterface
{
    /**
     * Clave que referencia este parser desde `ProviderParser.key`.
     */
    public function key(): string;

    /**
     * Interpreta el documento, o devuelve `null` si no reconoce su formato.
     *
     * Devolver `null` es la respuesta correcta ante un documento que no encaja:
     * el pipeline probará el siguiente parser y, si ninguno acierta, seguirá
     * con el extractor genérico.
     */
    public function parse(ProviderParserContext $context): ?ProviderParseResult;
}
