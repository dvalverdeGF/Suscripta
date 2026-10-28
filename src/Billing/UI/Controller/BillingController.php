<?php

declare(strict_types=1);

namespace App\Billing\UI\Controller;

use App\Billing\Domain\Repository\SalesInvoiceRepositoryInterface;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * «Facturación»: lo que la organización paga a Suscripta (Fase 24).
 *
 * ## No es la pantalla de facturas de servicios
 *
 * `/facturas` (`app_invoices_index`) enseña lo que el cliente **recibe** de sus
 * proveedores y que Suscripta descubre en el correo. Esta enseña lo que el
 * cliente **paga a Suscripta**, que lo emite la pasarela. Son dos cosas
 * distintas con el mismo nombre, y mezclarlas en una lista haría que el total
 * de «lo que pagas» incluyera la cuota del propio producto (D-86).
 *
 * ## La suscripción de Suscripta no es una suscripción de servicios
 *
 * `Subscription` describe un contrato con un proveedor externo. La del plan
 * contratado vive aquí, en el panel de facturación, y se enseña aparte para que
 * nadie la confunda con un servicio descubierto.
 */
final class BillingController extends AbstractController
{
    #[Route('/facturacion', name: 'app_billing_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(
        SalesInvoiceRepositoryInterface $invoices,
        SubscriptionRepositoryInterface $subscriptions,
    ): Response {
        $current = null;

        foreach ($subscriptions->findBillable() as $subscription) {
            $current = $subscription;

            break;
        }

        return $this->render('billing/index.html.twig', [
            'invoices' => $invoices->findForOrganization(),
            'current' => $current,
            'paidThisYear' => $invoices->totalPaidSince(new DateTimeImmutable('first day of January this year')),
        ]);
    }
}
