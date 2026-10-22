<?php

declare(strict_types=1);

namespace App\Api\Application\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Datos de subida de un documento desde la API.
 *
 * ## Por qué el contenido va en base64
 *
 * La API es JSON de principio a fin: mezclar `multipart/form-data` obligaría
 * al integrador a tener dos clientes HTTP distintos (uno para listar y otro
 * para subir) y a negociar codificaciones que no son JSON. El coste es que
 * el base64 ocupa un 33 % más, y la contrapartida es que **toda** la API se
 * consume con un solo `fetch`.
 *
 * El límite de tamaño (20 MB) lo impone el caso de uso de subida, no este
 * DTO: es el mismo límite que el del backoffice.
 */
final readonly class CreateDocumentRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'El nombre del fichero es obligatorio.')]
        #[Assert\Length(max: 255, maxMessage: 'El nombre del fichero no puede pasar de 255 caracteres.')]
        public string $filename,
        #[SerializedName('content_base64')]
        #[Assert\NotBlank(message: 'El contenido del documento es obligatorio.')]
        public string $contentBase64,
        #[SerializedName('mime_type')]
        public ?string $mimeType = null,
        public ?string $type = null,
        #[SerializedName('service_id')]
        public ?string $serviceId = null,
    ) {
    }
}
