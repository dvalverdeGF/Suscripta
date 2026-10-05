<?php

declare(strict_types=1);

namespace App\Documents\Application\Dto;

use App\Documents\Domain\Enum\DocumentSource;
use App\Documents\Domain\Enum\DocumentType;
use Symfony\Component\Uid\Uuid;

/**
 * Un fichero que entra en el sistema, venga de una subida manual o de un
 * adjunto de correo. El caso de uso no distingue el origen: solo cambia
 * `source` y, si procede, el mensaje del que salió.
 */
final readonly class DocumentUpload
{
    public function __construct(
        public string $contents,
        public string $originalFilename,
        public string $mimeType,
        public DocumentType $type = DocumentType::OTHER,
        public DocumentSource $source = DocumentSource::MANUAL_UPLOAD,
        public ?Uuid $serviceId = null,
        public ?Uuid $emailMessageId = null,
    ) {
    }
}
