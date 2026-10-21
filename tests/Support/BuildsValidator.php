<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Construye un Validator con el **mapeo por atributos** activado.
 *
 * ## Por qué hace falta
 *
 * `Validation::createValidator()` devuelve un validador que **no** lee las
 * restricciones `Assert\*` declaradas en los DTO: solo valida clases con
 * metadatos cargados por YAML, XML o PHP. En la aplicación, el contenedor de
 * Symfony lo construye con `framework.validation.enable_attributes: true`, así
 * que allí sí funciona; en un test unitario que arma el validador a mano, no.
 *
 * La diferencia es silenciosa y peligroso: `validate()` devuelve cero
 * violaciones y el test pasa creyendo que ha comprobado algo que en realidad
 * no se ha comprobado. Un DTO con `#[Assert\NotBlank]` devuelve vacío, y el
 * código que depende de esa restricción se comporta como si el dato fuera
 * válido.
 *
 * Este helper construye el validador **como lo construye la aplicación**, que
 * es lo que un test unitario necesita para ser representativo.
 */
trait BuildsValidator
{
    protected function buildValidator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator()
        ;
    }
}
