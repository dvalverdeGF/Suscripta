<?php

declare(strict_types=1);

namespace App\Processing\Domain\Matching;

use App\Processing\Domain\Dto\ExtractedDocument;
use App\Processing\Domain\Dto\ServiceMatchResult;
use Symfony\Component\Uid\Uuid;

/**
 * Puerto de emparejamiento entre un documento extraído y el inventario de
 * servicios (ARCHITECTURE.md §13.10, D-35).
 *
 * El pipeline depende de este contrato y no de la implementación: *qué señales
 * pesan* y *qué puntuación convierte una propuesta en certeza* es política de
 * producto, y tiene que poder cambiar sin tocar el orquestador.
 *
 * Regla de producto, y por eso el contrato no la esconde: **este puerto nunca
 * crea ni modifica un servicio**. Como mucho devuelve la mejor candidata y el
 * desglose que la justifica; la decisión la toma el usuario sobre un
 * `Discovery`.
 */
interface ServiceMatcherInterface
{
    /**
     * Empareja el documento con el inventario y devuelve la mejor candidata.
     *
     * @param ExtractedDocument $document   documento extraído del correo
     * @param Uuid|null         $providerId identidad del proveedor **resuelto en
     *                                      el catálogo**; `null` cuando el
     *                                      proveedor solo se ha deducido por
     *                                      nombre o cuando no se ha identificado
     */
    public function match(ExtractedDocument $document, ?Uuid $providerId = null): ServiceMatchResult;
}
