<?php

declare(strict_types=1);

namespace App\Documents\Application\Privacy;

use App\Documents\Domain\Repository\InvoiceRepositoryInterface;
use App\Shared\Application\Privacy\OrganizationDataProviderInterface;

use function count;

use Symfony\Component\Uid\Uuid;

/**
 * Sección «invoices» de la exportación: las facturas emitidas.
 *
 * Una fila por factura, con importes en la unidad menor de la moneda (céntimos)
 * para no perder precisión al exportar: 12.30 € son 1230 céntimos, y un
 * redondeo a dos decimales en el JSON rompería la suma.
 */
final readonly class InvoicesDataProvider implements OrganizationDataProviderInterface
{
    public function __construct(
        private InvoiceRepositoryInterface $invoices,
    ) {
    }

    public function section(): string
    {
        return 'invoices';
    }

    public function export(Uuid $organizationId): array
    {
        $invoices = $this->invoices->findAllForOrganization($organizationId);

        $rows = [];
        $total = 0;

        foreach ($invoices as $invoice) {
            $rows[] = [
                'id' => $invoice->getId()->toRfc4122(),
                'number' => $invoice->getNumber(),
                'status' => $invoice->getStatus()->value,
                'source' => $invoice->getSource()->value,
                'amount' => $invoice->getTotalAmountMinor(),
                'currency' => $invoice->getCurrency()->value,
                'issuedAt' => $invoice->getIssuedAt()->format('c'),
                'periodStart' => $invoice->getPeriodStart()?->format('c'),
                'periodEnd' => $invoice->getPeriodEnd()?->format('c'),
                'paidAt' => $invoice->getPaidAt()?->format('c'),
                'serviceId' => $invoice->getServiceId()?->toRfc4122(),
                'providerId' => $invoice->getProviderId()?->toRfc4122(),
                'documentId' => $invoice->getDocumentId()?->toRfc4122(),
            ];

            $total += $invoice->getTotalAmountMinor();
        }

        return [
            'meta' => [
                'count' => count($rows),
                'totalAmountMinor' => $total,
            ],
            'rows' => $rows,
        ];
    }
}
