<?php

declare(strict_types=1);

namespace App\Processing\Application\Privacy;

use App\Processing\Domain\Repository\ExtractionCacheRepositoryInterface;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use App\Shared\Application\Privacy\OrganizationDataProviderInterface;

use function count;

use Symfony\Component\Uid\Uuid;

/**
 * Sección «processing» de la exportación: lo que el sistema ha aprendido.
 *
 * Dos cosas muy distintas conviven aquí:
 *
 * 1. El **conocimiento aprendido** (reglas de proveedor, patrones de
 *    asunto, remitentes ignorados). Es un dato derivado del correo del
 *    usuario: se exporta para que pueda auditar lo que el sistema ha asumido
 *    sobre sus proveedores.
 * 2. La **caché de extracción**, que guarda el resultado de analizar un
 *    documento. Se exporta como resumen (cuántas entradas hay, de qué tipo),
 *    no con su contenido: son documentos del usuario repetidos, y ya están
 *    en la sección de documentos.
 */
final readonly class ProcessingDataProvider implements OrganizationDataProviderInterface
{
    public function __construct(
        private MailboxKnowledgeRepositoryInterface $knowledge,
        private ExtractionCacheRepositoryInterface $cache,
    ) {
    }

    public function section(): string
    {
        return 'processing';
    }

    public function export(Uuid $organizationId): array
    {
        $entries = $this->knowledge->findForOrganization($organizationId);

        $rows = [];

        foreach ($entries as $entry) {
            $rows[] = [
                'id' => $entry->getId()->toRfc4122(),
                'type' => $entry->getType()->value,
                'value' => $entry->getValue(),
                'source' => $entry->getSource()->value,
                'confidence' => $entry->getConfidence(),
                'hitCount' => $entry->getHitCount(),
                'promoted' => $entry->isPromoted(),
                'emailAccountId' => $entry->getEmailAccountId()->toRfc4122(),
                'providerId' => $entry->getProviderId()?->toRfc4122(),
                'documentType' => $entry->getDocumentType()?->value,
                'billingPeriod' => $entry->getBillingPeriod()?->value,
                'lastUsedAt' => $entry->getLastUsedAt()?->format('c'),
                'createdAt' => $entry->getCreatedAt()->format('c'),
            ];
        }

        return [
            'meta' => [
                'knowledgeCount' => count($rows),
                'extractionCacheCount' => $this->cache->countForOrganization(),
            ],
            'rows' => $rows,
        ];
    }
}
