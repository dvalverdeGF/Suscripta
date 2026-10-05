<?php

declare(strict_types=1);

namespace App\Documents\UI\Form;

use App\Documents\Domain\Enum\DocumentType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

/**
 * Objeto mutable que rellena el formulario de subida.
 *
 * El fichero no se lee aquí: el controlador lo convierte en un `DocumentUpload`
 * con el contenido ya cargado, para que el caso de uso no dependa de la capa
 * HTTP.
 */
final class DocumentFormData
{
    public ?UploadedFile $file = null;
    public ?DocumentType $type = DocumentType::INVOICE;
    public ?Uuid $serviceId = null;
}
