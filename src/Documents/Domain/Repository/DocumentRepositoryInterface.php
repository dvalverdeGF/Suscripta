<?php

declare(strict_types=1);

namespace App\Documents\Domain\Repository;

use App\Documents\Domain\Entity\Document;
use App\Documents\Domain\Enum\DocumentType;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

interface DocumentRepositoryInterface
{
    public function find(Uuid $id): ?Document;

    /**
     * @return list<Document>
     */
    public function findForOrganization(?DocumentType $type = null, int $limit = 100): array;

    /**
     * @return list<Document>
     */
    public function findForService(Uuid $serviceId): array;

    /**
     * @return list<Document>
     */
    public function findForInvoice(Uuid $invoiceId): array;

    /**
     * @return list<Document>
     */
    public function findForEmailMessage(Uuid $emailMessageId): array;

    /**
     * Deduplicación por huella (D-27): la misma factura que llega por dos
     * buzones es un solo documento. Devuelve también los borrados, porque
     * volver a subir algo que el usuario borró no debe resucitarlo en silencio.
     */
    public function findByChecksum(string $checksumSha256): ?Document;

    public function countForOrganization(): int;

    /**
     * @return array<string, int> clave = valor del enum de tipo
     */
    public function countByType(): array;

    public function save(Document $document, bool $flush = true): void;

    public function remove(Document $document, bool $flush = true): void;

    public function flush(): void;

    /**
     * Documentos marcados como borrados antes del umbral (porOrganization).
     *
     * La purga física necesita la clave de almacenamiento para borrar también
     * el binario, así que se devuelven las entidades y no solo un recuento.
     *
     * @return list<Document>
     */
    public function findSoftDeletedBefore(DateTimeImmutable $threshold, int $limit = 500): array;

    /**
     * Borra definitivamente los documentos marcados como borrados.
     *
     * @return int número de documentos eliminados
     */
    public function removeForOrganization(Uuid $organizationId): int;

    /**
     * Todos los documentos de una organización concreta.
     *
     * A diferencia de `findForOrganization()`, que usa la organización activa
     * del contexto, este método toma la organización como dato: lo necesitan
     * la exportación de datos y la purga.
     *
     * @return list<Document>
     */
    public function findAllForOrganization(Uuid $organizationId): array;
}
