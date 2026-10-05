<?php

declare(strict_types=1);

namespace App\Tests\Functional\Documents;

use App\Documents\Domain\Entity\Invoice;
use App\Documents\Domain\Enum\InvoiceSource;
use App\Documents\Domain\Enum\InvoiceStatus;
use App\Documents\Domain\Repository\InvoiceRepositoryInterface;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Tests\Support\Services\ServiceEventAssertions;

use function sprintf;
use function strlen;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Recorrido funcional de las facturas.
 *
 * Una factura es el hecho económico (D-19): se registra aunque no tengamos el
 * fichero, y es lo que alimenta el historial de precios y la evolución del
 * gasto. Por eso lo que se prueba aquí es que el alta sea fiable y que la
 * deduplicación no duplique cobros.
 */
final class InvoiceTest extends WebTestCase
{
    use ServiceEventAssertions;

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $this->client->loginUser($this->user);
    }

    public function testIndexIsReachableAndEmpty(): void
    {
        $this->client->request('GET', '/invoices');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Facturas');
        self::assertSelectorTextContains('.empty-state__title', 'Todavía no hay facturas registradas');
    }

    public function testRegisterAnInvoice(): void
    {
        $invoice = $this->register([
            'invoice_form[issuedAt]' => '2026-10-03',
            'invoice_form[total]' => '29,90',
            'invoice_form[currency]' => 'EUR',
            'invoice_form[number]' => 'OVH-2026-10-0001',
            'invoice_form[status]' => InvoiceStatus::PAID->value,
            'invoice_form[paidAt]' => '2026-10-04',
        ]);

        self::assertSame(2990, $invoice->getTotalAmountMinor());
        self::assertSame('OVH-2026-10-0001', $invoice->getNumber());
        self::assertSame(InvoiceStatus::PAID, $invoice->getStatus());
        self::assertNotNull($invoice->getPaidAt());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Factura de 29,90 € registrada.');
        self::assertSelectorTextContains('h1', '29,90 €');
        self::assertSelectorTextContains('.definition-list', 'OVH-2026-10-0001');
    }

    public function testRegisteringTheSameInvoiceTwiceDoesNotDuplicateIt(): void
    {
        $this->register([
            'invoice_form[issuedAt]' => '2026-10-03',
            'invoice_form[total]' => '29,90',
            'invoice_form[currency]' => 'EUR',
            'invoice_form[number]' => 'OVH-2026-10-0001',
            'invoice_form[status]' => InvoiceStatus::PAID->value,
        ]);

        $this->client->followRedirect();

        $this->client->request('GET', '/invoices/new');
        $this->client->submitForm('Registrar factura', [
            'invoice_form[issuedAt]' => '2026-10-03',
            'invoice_form[total]' => '29,90',
            'invoice_form[currency]' => 'EUR',
            'invoice_form[number]' => 'OVH-2026-10-0001',
            'invoice_form[status]' => InvoiceStatus::PAID->value,
        ]);

        self::assertResponseRedirects();
        self::assertSame(1, $this->repository()->countForOrganization());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'ya estaba registrada');
    }

    public function testRegisteringAnInvoiceAttachesItToAServiceAndRecordsTheRenewal(): void
    {
        $service = $this->createService('OVH VPS');

        $invoice = $this->register([
            'invoice_form[issuedAt]' => '2026-10-03',
            'invoice_form[total]' => '29,90',
            'invoice_form[currency]' => 'EUR',
            'invoice_form[serviceId]' => $service->getId()->toRfc4122(),
            'invoice_form[status]' => InvoiceStatus::PAID->value,
        ]);

        self::assertSame($service->getId()->toRfc4122(), $invoice->getServiceId()?->toRfc4122());

        $reloaded = $this->reloadService($service);
        $events = $reloaded->getEvents();

        self::assertCount(2, $events, 'Alta del servicio y renovación.');
        self::assertSame(ServiceEventType::RENEWED, self::eventAt($reloaded, 1)->getType());
        self::assertSame('2026-10-03', self::eventAt($reloaded, 1)->getOccurredAt()->format('Y-m-d'));
    }

    public function testRegisteringAnInvoiceWithANegativeAmountIsRejected(): void
    {
        $this->client->request('GET', '/invoices/new');
        $this->client->submitForm('Registrar factura', [
            'invoice_form[issuedAt]' => '2026-10-03',
            'invoice_form[total]' => '-29,90',
            'invoice_form[currency]' => 'EUR',
            'invoice_form[status]' => InvoiceStatus::UNKNOWN->value,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert--danger', 'no puede ser negativo');
        self::assertSame(0, $this->repository()->countForOrganization());
    }

    public function testRegisteringAnInvoiceWithoutAnAmountIsRejected(): void
    {
        $this->client->request('GET', '/invoices/new');
        $this->client->submitForm('Registrar factura', [
            'invoice_form[issuedAt]' => '2026-10-03',
            'invoice_form[currency]' => 'EUR',
            'invoice_form[status]' => InvoiceStatus::UNKNOWN->value,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__error', 'Indica el importe');
    }

    public function testRegisteringAnInvoiceWithAnInvertedPeriodIsRejected(): void
    {
        $this->client->request('GET', '/invoices/new');
        $this->client->submitForm('Registrar factura', [
            'invoice_form[issuedAt]' => '2026-10-03',
            'invoice_form[total]' => '29,90',
            'invoice_form[currency]' => 'EUR',
            'invoice_form[periodStart]' => '2026-10-31',
            'invoice_form[periodEnd]' => '2026-10-01',
            'invoice_form[status]' => InvoiceStatus::UNKNOWN->value,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert--danger', 'no puede ser anterior');
        self::assertSame(0, $this->repository()->countForOrganization());
    }

    public function testIndexFiltersByStatus(): void
    {
        $this->register([
            'invoice_form[issuedAt]' => '2026-10-03',
            'invoice_form[total]' => '29,90',
            'invoice_form[currency]' => 'EUR',
            'invoice_form[number]' => 'PAGADA',
            'invoice_form[status]' => InvoiceStatus::PAID->value,
        ]);

        $this->client->followRedirect();

        $this->client->request('GET', '/invoices/new');
        $this->client->submitForm('Registrar factura', [
            'invoice_form[issuedAt]' => '2026-10-04',
            'invoice_form[total]' => '9,99',
            'invoice_form[currency]' => 'EUR',
            'invoice_form[number]' => 'FALLIDA',
            'invoice_form[status]' => InvoiceStatus::FAILED->value,
        ]);

        $this->client->followRedirect();

        $this->client->request('GET', '/invoices?status=failed');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'FALLIDA');
        self::assertSelectorTextNotContains('table', 'PAGADA');
    }

    public function testShowOfAnUnknownInvoiceIsNotFound(): void
    {
        $this->client->request('GET', '/invoices/'.Uuid::v7()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnInvoiceRegisteredByHandIsMarkedAsManual(): void
    {
        $invoice = $this->register([
            'invoice_form[issuedAt]' => '2026-10-03',
            'invoice_form[total]' => '29,90',
            'invoice_form[currency]' => 'EUR',
            'invoice_form[status]' => InvoiceStatus::PAID->value,
            'invoice_form[source]' => InvoiceSource::MANUAL->value,
        ]);

        self::assertSame(InvoiceSource::MANUAL, $invoice->getSource());

        $this->client->request('GET', '/invoices/'.$invoice->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.app-main', 'Registrada a mano');
    }

    public function testAnInvoiceCanBeMarkedAsDetectedInTheMailbox(): void
    {
        $invoice = $this->register([
            'invoice_form[issuedAt]' => '2026-10-03',
            'invoice_form[total]' => '29,90',
            'invoice_form[currency]' => 'EUR',
            'invoice_form[status]' => InvoiceStatus::PAID->value,
            'invoice_form[source]' => InvoiceSource::EMAIL_DISCOVERY->value,
        ]);

        self::assertSame(InvoiceSource::EMAIL_DISCOVERY, $invoice->getSource());

        $this->client->request('GET', '/invoices/'.$invoice->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.app-main', 'Detectada en tu correo');
    }

    /**
     * @param array<string, string> $values
     */
    private function register(array $values): Invoice
    {
        $this->client->request('GET', '/invoices/new');
        $this->client->submitForm('Registrar factura', $values);

        self::assertResponseRedirects();

        $location = (string) $this->client->getResponse()->headers->get('Location');
        $invoice = $this->repository()->find(Uuid::fromString(substr($location, strlen('/invoices/'))));

        self::assertInstanceOf(Invoice::class, $invoice);

        return $invoice;
    }

    private function createService(string $name): Service
    {
        $this->client->request('GET', '/services/new');
        $this->client->submitForm('Añadir servicio', [
            'service_form[name]' => $name,
            'service_form[amount]' => '11,99',
            'service_form[currency]' => 'EUR',
            'service_form[billingPeriod]' => BillingPeriod::MONTHLY->value,
            'service_form[billingIntervalCount]' => '1',
            'service_form[autoRenews]' => '1',
        ]);

        self::assertResponseRedirects();

        foreach ($this->serviceRepository()->findForOrganization(ServiceFilters::none()) as $service) {
            if ($service->getName() === $name) {
                return $service;
            }
        }

        self::fail(sprintf('No se ha creado el servicio «%s».', $name));
    }

    private function reloadService(Service $service): Service
    {
        $reloaded = $this->serviceRepository()->find($service->getId());

        self::assertInstanceOf(Service::class, $reloaded);

        return $reloaded;
    }

    private function repository(): InvoiceRepositoryInterface
    {
        return self::getContainer()->get(InvoiceRepositoryInterface::class);
    }

    private function serviceRepository(): ServiceRepositoryInterface
    {
        return self::getContainer()->get(ServiceRepositoryInterface::class);
    }
}
