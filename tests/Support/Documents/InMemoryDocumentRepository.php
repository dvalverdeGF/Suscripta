<?php

declare(strict_types=1);

namespace App\Tests\Support\Documents;

use App\Documents\Domain\Entity\Document;
use App\Documents\Domain\Enum\DocumentType;
use App\Documents\Domain\Repository\DocumentRepositoryInterface;

use function array_values;
use function count;

use const PHP_INT_MAX;

use Symfony\Component\Uid\Uuid;

/**
 * Repositorio de documentos en memoria.
 *
 * La deduplicación por huella solo se puede probar con un doble que **recuerde**
 * lo guardado: si `findByChecksum()` devolviera siempre `null`, la prueba de
 * "subir dos veces el mismo fichero no crea dos documentos" pasaría sin
 * comprobar nada.
 */
final class InMemoryDocumentRepository implements DocumentRepositoryInterface
{
    /** @var array<string, Document> clave = identificador en formato RFC 4122 */
    private array $documents = [];

    public int $flushCount = 0;

    public function find(Uuid $id): ?Document
    {
        return $this->documents[$id->toRfc4122()] ?? null;
    }

    public function findForOrganization(?DocumentType $type = null, int $limit = 100): array
    {
        $result = [];

        foreach ($this->documents as $document) {
            if ($document->isDeleted()) {
                continue;
            }

            if (null !== $type && $document->getType() !== $type) {
                continue;
            }

            $result[] = $document;

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    public function findForService(Uuid $serviceId): array
    {
        return $this->filter(static fn (Document $document): bool => $document->getServiceId()?->toRfc4122() === $serviceId->toRfc4122());
    }

    public function findForInvoice(Uuid $invoiceId): array
    {
        return $this->filter(static fn (Document $document): bool => $document->getInvoiceId()?->toRfc4122() === $invoiceId->toRfc4122());
    }

    public function findForEmailMessage(Uuid $emailMessageId): array
    {
        return $this->filter(static fn (Document $document): bool => $document->getEmailMessageId()?->toRfc4122() === $emailMessageId->toRfc4122());
    }

    public function findByChecksum(string $checksumSha256): ?Document
    {
        foreach ($this->documents as $document) {
            if ($document->getChecksumSha256() === $checksumSha256) {
                return $document;
            }
        }

        return null;
    }

    public function countForOrganization(): int
    {
        return count($this->findForOrganization(null, PHP_INT_MAX));
    }

    public function countByType(): array
    {
        $counts = [];

        foreach ($this->documents as $document) {
            if ($document->isDeleted()) {
                continue;
            }

            $key = $document->getType()->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    public function save(Document $document, bool $flush = true): void
    {
        $this->documents[$document->getId()->toRfc4122()] = $document;
    }

    public function remove(Document $document, bool $flush = true): void
    {
        unset($this->documents[$document->getId()->toRfc4122()]);
    }

    public function flush(): void
    {
        ++$this->flushCount;
    }

    /**
     * @return list<Document>
     */
    public function all(): array
    {
        return array_values($this->documents);
    }

    /**
     * @param callable(Document): bool $predicate
     *
     * @return list<Document>
     */
    private function filter(callable $predicate): array
    {
        $result = [];

        foreach ($this->documents as $document) {
            if ($document->isDeleted()) {
                continue;
            }

            if ($predicate($document)) {
                $result[] = $document;
            }
        }

        return $result;
    }
}
