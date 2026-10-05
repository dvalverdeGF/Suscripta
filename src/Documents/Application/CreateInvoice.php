<?php

declare(strict_types=1);

namespace App\Documents\Application;

use App\Documents\Application\Dto\InvoiceInput;
use App\Documents\Domain\Entity\Invoice;
use App\Documents\Domain\Enum\InvoiceStatus;
use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Documents\Domain\Repository\InvoiceRepositoryInterface;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Registra un cobro documentado.
 *
 * La deduplicación es por proveedor, número, importe, moneda y fecha: la misma
 * factura que llega por dos buzones, o que se sube a mano después de haberla
 * detectado en el correo, es un solo cobro (D-27).
 */
final readonly class CreateInvoice
{
    public function __construct(
        private InvoiceRepositoryInterface $invoices,
        private DocumentRepositoryInterface $documents,
        private ServiceRepositoryInterface $services,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * @return array{invoice: Invoice, created: bool}
     */
    public function __invoke(InvoiceInput $input, ?Uuid $actorUserId = null): array
    {
        $organizationId = $this->tenantContext->requireOrganizationId();

        if ($input->total->isNegative()) {
            throw new InvalidArgumentException('El importe de una factura no puede ser negativo.');
        }

        $invoice = new Invoice(
            organizationId: $organizationId,
            issuedAt: $input->issuedAt,
            total: $input->total,
            source: $input->source,
        );

        $invoice->setProviderId($input->providerId);
        $invoice->setNumber($input->number);
        $invoice->setBillingPeriod($input->periodStart, $input->periodEnd);

        $existing = $this->invoices->findByDedupKey($invoice->buildDedupKey());

        if (null !== $existing) {
            // Ya la teníamos. Aprovechamos para completar lo que falte: si
            // ahora sabemos a qué servicio pertenece, lo anotamos.
            if (null === $existing->getServiceId() && null !== $input->serviceId) {
                $existing->attachToService($input->serviceId);
                $this->invoices->save($existing);
            }

            return ['invoice' => $existing, 'created' => false];
        }

        // El documento se resuelve **antes** de enlazarlo: una factura que
        // apunta a un documento inexistente rompe la vista de detalle y deja
        // una referencia colgando que nadie va a limpiar.
        $document = null !== $input->documentId ? $this->documents->find($input->documentId) : null;

        $invoice->attachToService($input->serviceId);
        $invoice->attachToDocument($document?->getId());

        if (null !== $input->paidAt) {
            $invoice->markPaid($input->paidAt);
        } elseif (InvoiceStatus::UNKNOWN !== $input->status) {
            $this->applyStatus($invoice, $input->status);
        }

        $this->invoices->save($invoice);

        if (null !== $document) {
            $document->attachToInvoice($invoice->getId());
            $this->documents->save($document);
        }

        if (null !== $input->serviceId) {
            $this->recordServiceEvent($input->serviceId, $invoice, $actorUserId);
        }

        return ['invoice' => $invoice, 'created' => true];
    }

    private function applyStatus(Invoice $invoice, InvoiceStatus $status): void
    {
        match ($status) {
            InvoiceStatus::FAILED => $invoice->markFailed(),
            InvoiceStatus::REFUNDED => $invoice->markRefunded(),
            default => null,
        };
    }

    private function recordServiceEvent(Uuid $serviceId, Invoice $invoice, ?Uuid $actorUserId): void
    {
        $service = $this->services->find($serviceId);

        if (null === $service) {
            return;
        }

        $service->recordEvent(ServiceEventType::RENEWED, $actorUserId, [
            'invoiceId' => $invoice->getId()->toRfc4122(),
            'amountMinor' => $invoice->getTotalAmountMinor(),
            'currency' => $invoice->getCurrency()->value,
            'issuedAt' => $invoice->getIssuedAt()->format('Y-m-d'),
        ], $invoice->getIssuedAt());

        $this->services->save($service);
    }
}
