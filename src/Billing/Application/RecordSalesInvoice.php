<?php

declare(strict_types=1);

namespace App\Billing\Application;

use App\Billing\Domain\Entity\SalesInvoice;
use App\Billing\Domain\Repository\SalesInvoiceRepositoryInterface;
use App\Billing\Domain\ValueObject\GatewayInvoice;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;
use Psr\Log\LoggerInterface;

use function sprintf;

/**
 * Guarda o actualiza una factura de venta a partir de lo que dice la pasarela
 * (Fase 24).
 *
 * ## La organización se resuelve en dos pasos
 *
 * Primero por los metadatos del evento, que es lo normal. Si no vienen —una
 * factura emitida a mano desde el panel de Stripe—, se busca la suscripción por
 * su referencia externa y se toma la organización de ahí. Si tampoco, no se
 * guarda: una factura sin dueño no se puede enseñar a nadie y ocuparía una fila
 * para siempre.
 *
 * ## Es un `upsert` y no un `insert`
 *
 * La misma factura llega varias veces: `invoice.created`, `invoice.finalized`,
 * `invoice.paid` y la conciliación nocturna. Se busca por el identificador de
 * la pasarela y se sincroniza, de modo que el estado final sea el de la última
 * notificación y no el de la primera que llegó.
 */
final readonly class RecordSalesInvoice
{
    public function __construct(
        private SalesInvoiceRepositoryInterface $invoices,
        private SubscriptionRepositoryInterface $subscriptions,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(GatewayInvoice $remote): ?SalesInvoice
    {
        $subscription = null !== $remote->subscriptionExternalId
            ? $this->subscriptions->findByExternalReference($remote->subscriptionExternalId)
            : null;

        $organizationId = $remote->organizationId ?? $subscription?->getOrganizationId();

        if (null === $organizationId) {
            $this->logger->warning('Se ignora una factura de la pasarela sin organización identificable.', [
                'external_id' => $remote->externalId,
                'subscription' => $remote->subscriptionExternalId,
            ]);

            return null;
        }

        $invoice = $this->invoices->findByExternalId($remote->externalId);

        if (null === $invoice) {
            $invoice = new SalesInvoice(
                organizationId: $organizationId,
                externalInvoiceId: $remote->externalId,
                amount: $remote->amount(),
                status: $remote->status,
                issuedAt: $remote->issuedAt,
                subscriptionId: $subscription?->getId(),
                number: $remote->number,
            );

            $invoice->sync(
                status: $remote->status,
                amount: $remote->amount(),
                number: $remote->number,
                paidAt: $remote->paidAt,
                hostedUrl: $remote->hostedUrl,
                pdfUrl: $remote->pdfUrl,
            );

            $this->invoices->save($invoice);

            return $invoice;
        }

        $invoice->sync(
            status: $remote->status,
            amount: $remote->amount(),
            number: $remote->number,
            paidAt: $remote->paidAt,
            hostedUrl: $remote->hostedUrl,
            pdfUrl: $remote->pdfUrl,
        );

        if (null === $invoice->getSubscriptionId() && null !== $subscription) {
            $invoice->linkSubscription($subscription->getId());
        }

        $this->invoices->save($invoice);

        $this->logger->info(sprintf('Factura de venta sincronizada: %s.', $remote->number ?? $remote->externalId), [
            'status' => $remote->status->value,
        ]);

        return $invoice;
    }
}
