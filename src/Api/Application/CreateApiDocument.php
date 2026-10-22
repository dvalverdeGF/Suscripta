<?php

declare(strict_types=1);

namespace App\Api\Application;

use App\Api\Application\Dto\CreateDocumentRequest;
use App\Api\Application\ReadModel\ResourceMapper;
use App\Documents\Application\Dto\DocumentUpload;
use App\Documents\Application\UploadDocument;
use App\Documents\Domain\Enum\DocumentSource;
use App\Documents\Domain\Enum\DocumentType;
use App\Documents\Domain\Repository\DocumentViewRepositoryInterface;
use App\Shared\Domain\Exception\InvalidArgumentException;

use function base64_decode;
use function sprintf;
use function trim;

use const FILEINFO_MIME_TYPE;

use finfo;

/**
 * Subida de un documento desde la API.
 *
 * ## El contenido se decodifica aquí
 *
 * El caso de uso de subida del módulo `Documents` trabaja con bytes. La
 * API recibe base64 y lo convierte, comprobando que sea base64 válido: un
 * texto que no lo es produciría bytes basura que el detector de tipos
 * rechazaría con un mensaje confuso («tipo no permitido» en lugar de «el
 * base64 no es válido»).
 *
 * ## La deduplicación es la del módulo
 *
 * Si el mismo fichero ya está subido, `UploadDocument` devuelve el que hay
 * en lugar de crear un duplicado. La API hereda ese comportamiento tal cual:
 * dos integraciones que suben la misma factura no generan dos documentos.
 */
final readonly class CreateApiDocument
{
    public function __construct(
        private UploadDocument $uploadDocument,
        private DocumentViewRepositoryInterface $documents,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(CreateDocumentRequest $request): array
    {
        $contents = base64_decode(trim($request->contentBase64), true);

        if (false === $contents) {
            throw new InvalidArgumentException('El contenido del documento no es base64 válido.');
        }

        $type = $this->type($request->type);

        $upload = new DocumentUpload(
            contents: $contents,
            originalFilename: $request->filename,
            mimeType: $request->mimeType ?? $this->detectMimeType($contents),
            type: $type,
            source: DocumentSource::MANUAL_UPLOAD,
            serviceId: ApiValueParser::uuid($request->serviceId, 'service_id'),
        );

        $result = ($this->uploadDocument)($upload);

        $document = $result['document'];

        $view = $this->documents->findOne($document->getId());

        if (null === $view) {
            throw new \RuntimeException('El documento recién subido no se puede leer.');
        }

        return ResourceMapper::document($view);
    }

    private function type(?string $value): DocumentType
    {
        if (null === $value || '' === trim($value)) {
            return DocumentType::OTHER;
        }

        $type = DocumentType::tryFrom(trim($value));

        if (null === $type) {
            throw new InvalidArgumentException(sprintf('El tipo de documento «%s» no existe.', $value));
        }

        return $type;
    }

    /**
     * Deduce el tipo del contenido cuando el integrador no lo declara.
     *
     * El integrador no tiene que saber qué tipos acepta el sistema: lo dice
     * el contenido. Si lo declara, se usa el declarado (y el caso de uso
     * decide si lo acepta); si no, se mira qué **parece** ser el fichero.
     */
    private function detectMimeType(string $contents): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);

        $detected = $finfo->buffer($contents);

        if (false === $detected || '' === $detected) {
            return 'application/octet-stream';
        }

        return $detected;
    }
}
