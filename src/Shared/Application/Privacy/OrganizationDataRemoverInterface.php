<?php

declare(strict_types=1);

namespace App\Shared\Application\Privacy;

use Symfony\Component\Uid\Uuid;

/**
 * Borra todo lo que una organización tiene en un módulo.
 *
 * Es el otro lado del puerto de privacidad: lo que un módulo publica para
 * exportar, lo borra él mismo. El borrado en cascada se coordina desde fuera
 * (el módulo de privacidad), pero **cada módulo borra lo suyo**, porque solo él
 * sabe qué hay que respetar (documentos en disco, Extractos de cuerpo,
 * conocimiento aprendido...).
 */
interface OrganizationDataRemoverInterface
{
    /**
     * Nombre del módulo. Solo para el informe de lo que se ha borrado.
     */
    public function module(): string;

    /**
     * Orden en que se borra el módulo: **menor antes**.
     *
     * Cada módulo sabe cuándo puede irse: el de descubrimiento borra antes
     * que el de servicios porque sus filas apuntan a los servicios, y el de
     * identidad borra el último porque se lleva por delante la organización
     * entera. Es conocimiento local del módulo, no una tabla central: el
     * coordinador solo ordena.
     */
    public function priority(): int;

    /**
     * Borra los datos de la organización.
     *
     * @return int número de elementos eliminados
     */
    public function removeForOrganization(Uuid $organizationId): int;
}
