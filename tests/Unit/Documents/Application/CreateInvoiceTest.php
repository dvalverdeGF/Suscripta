<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documents\Application;

use App\Documents\Application\CreateInvoice;
use App\Documents\Application\Dto\InvoiceInput;
use App\Documents\Domain\Entity\Document;
use App\Documents\Domain\Enum\DocumentSource;
use App\Documents\Domain\Enum\DocumentType;
use App\Documents\Domain\Enum\InvoiceSource;
use App\Documents\Domain\Enum\InvoiceStatus;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Enum\ServiceSource;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Documents\InMemoryDocumentRepository;
use App\Tests\Support\Documents\InMemoryInvoiceRepository;
use App\Tests\Support\Services\ServiceEventAssertions;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function str_repeat;

use Symfony\Component\Uid\Uuid;

/**
 * Registrar un cobro es el acto que convierte "creo que pago esto" en "esto es
 * lo que pagué". Su propiedad crítica es la deduplicación: la misma factura
 * detectada en el correo y subida a mano después es **un solo cobro**, no dos.
 */
final class CreateInvoiceTest extends TestCase
{
    use ServiceEventAssertions;

    private Uuid $organizationId;
    private InMemoryInvoiceRepository $invoices;
    private InMemoryDocumentRepository $documents;
    private ServiceRepositoryInterface&MockObject $services;
    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->invoices = new InMemoryInvoiceRepository();
        $this->documents = new InMemoryDocumentRepository();
        $this->services = $this->createMock(ServiceRepositoryInterface::class);
        $this->tenantContext = new TenantContext();
        $this->tenantContext->setOrganizationId($this->organizationId);
    }

    public function testItRegistersTheInvoice(): void
    {
        $result = $this->create();

        self::assertTrue($result['created']);
        self::assertSame(2990, $result['invoice']->getTotalAmountMinor());
        self::assertSame(Currency::EUR, $result['invoice']->getCurrency());
        self::assertSame(1, $this->invoices->countForOrganization());
    }

    public function testItStartsUnknownWhenNothingIsSaidAboutThePayment(): void
    {
        $result = $this->create();

        self::assertSame(InvoiceStatus::UNKNOWN, $result['invoice']->getStatus());
        self::assertNull($result['invoice']->getPaidAt());
    }

    public function testItMarksItPaidWhenThePaymentDateIsKnown(): void
    {
        $paidAt = new DateTimeImmutable('2026-10-03 08:30:00');

        $result = $this->create(paidAt: $paidAt);

        self::assertSame(InvoiceStatus::PAID, $result['invoice']->getStatus());
        self::assertSame($paidAt->format('c'), $result['invoice']->getPaidAt()?->format('c'));
    }

    public function testThePaymentDateWinsOverAnExplicitStatus(): void
    {
        $result = $this->create(
            paidAt: new DateTimeImmutable('2026-10-03'),
            status: InvoiceStatus::FAILED,
        );

        self::assertSame(InvoiceStatus::PAID, $result['invoice']->getStatus());
    }

    public function testItAppliesAFailedStatus(): void
    {
        $result = $this->create(status: InvoiceStatus::FAILED);

        self::assertSame(InvoiceStatus::FAILED, $result['invoice']->getStatus());
    }

    public function testItAppliesARefundedStatus(): void
    {
        $result = $this->create(status: InvoiceStatus::REFUNDED);

        self::assertSame(InvoiceStatus::REFUNDED, $result['invoice']->getStatus());
    }

    public function testItRejectsANegativeAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->create(total: Money::fromDecimalString('-1,00', Currency::EUR));
    }

    public function testItAcceptsAZeroAmount(): void
    {
        $result = $this->create(total: Money::zero(Currency::EUR));

        self::assertTrue($result['created']);
        self::assertSame(0, $result['invoice']->getTotalAmountMinor());
    }

    public function testRegisteringTheSameInvoiceTwiceDoesNotDuplicateIt(): void
    {
        $providerId = Uuid::v7();

        $first = $this->create(providerId: $providerId, number: 'INV-2026-10');
        $second = $this->create(providerId: $providerId, number: 'INV-2026-10');

        self::assertTrue($first['created']);
        self::assertFalse($second['created']);
        self::assertSame(
            $first['invoice']->getId()->toRfc4122(),
            $second['invoice']->getId()->toRfc4122(),
        );
        self::assertSame(1, $this->invoices->countForOrganization());
    }

    public function testTheSameAmountOnADifferentDayIsADifferentInvoice(): void
    {
        $providerId = Uuid::v7();

        $this->create(providerId: $providerId, issuedAt: new DateTimeImmutable('2026-10-03'));
        $this->create(providerId: $providerId, issuedAt: new DateTimeImmutable('2026-11-03'));

        self::assertSame(2, $this->invoices->countForOrganization());
    }

    public function testItCompletesTheServiceOfAnAlreadyKnownInvoice(): void
    {
        $providerId = Uuid::v7();
        $serviceId = Uuid::v7();

        $this->create(providerId: $providerId, number: 'INV-1');
        $second = $this->create(providerId: $providerId, number: 'INV-1', serviceId: $serviceId);

        self::assertFalse($second['created']);
        self::assertSame($serviceId->toRfc4122(), $second['invoice']->getServiceId()?->toRfc4122());
    }

    public function testItDoesNotOverwriteAnAlreadyAssignedService(): void
    {
        $providerId = Uuid::v7();
        $original = Uuid::v7();
        $other = Uuid::v7();

        $this->create(providerId: $providerId, number: 'INV-1', serviceId: $original);
        $second = $this->create(providerId: $providerId, number: 'INV-1', serviceId: $other);

        self::assertSame($original->toRfc4122(), $second['invoice']->getServiceId()?->toRfc4122());
    }

    public function testItLinksTheDocumentBothWays(): void
    {
        $document = $this->document();
        $this->documents->save($document);

        $result = $this->create(documentId: $document->getId());

        self::assertSame($document->getId()->toRfc4122(), $result['invoice']->getDocumentId()?->toRfc4122());
        self::assertSame($result['invoice']->getId()->toRfc4122(), $document->getInvoiceId()?->toRfc4122());
    }

    public function testItIgnoresAnUnknownDocument(): void
    {
        $result = $this->create(documentId: Uuid::v7());

        self::assertTrue($result['created']);
        self::assertNull($result['invoice']->getDocumentId());
    }

    public function testItRecordsARenewalOnTheService(): void
    {
        $service = $this->service();
        $this->services->method('find')->willReturn($service);

        $this->create(serviceId: $service->getId());

        $events = $service->getEvents();

        self::assertCount(1, $events);
        self::assertSame(ServiceEventType::RENEWED, self::eventAt($service, 0)->getType());
    }

    public function testTheRenewalIsDatedOnTheInvoiceDay(): void
    {
        $service = $this->service();
        $this->services->method('find')->willReturn($service);
        $issuedAt = new DateTimeImmutable('2026-10-03');

        $this->create(serviceId: $service->getId(), issuedAt: $issuedAt);

        self::assertSame($issuedAt->format('Y-m-d'), self::eventAt($service, 0)->getOccurredAt()->format('Y-m-d'));
    }

    public function testItDoesNotRecordAnEventWhenTheServiceIsUnknown(): void
    {
        $this->services->method('find')->willReturn(null);

        $result = $this->create(serviceId: Uuid::v7());

        self::assertTrue($result['created']);
    }

    public function testItStoresTheBillingPeriod(): void
    {
        $result = $this->create(
            periodStart: new DateTimeImmutable('2026-10-01'),
            periodEnd: new DateTimeImmutable('2026-10-31'),
        );

        self::assertSame('2026-10-01', $result['invoice']->getPeriodStart()?->format('Y-m-d'));
        self::assertSame('2026-10-31', $result['invoice']->getPeriodEnd()?->format('Y-m-d'));
    }

    public function testItRefusesToWorkWithoutAnActiveOrganization(): void
    {
        $this->tenantContext->setOrganizationId(null);

        $this->expectException(InvalidArgumentException::class);

        $this->create();
    }

    public function testItIsManualByDefault(): void
    {
        self::assertSame(InvoiceSource::MANUAL, $this->create()['invoice']->getSource());
    }

    public function testItKeepsTheSourceItWasGiven(): void
    {
        $result = $this->create(source: InvoiceSource::EMAIL_DISCOVERY);

        self::assertSame(InvoiceSource::EMAIL_DISCOVERY, $result['invoice']->getSource());
    }

    /**
     * @return array{invoice: \App\Documents\Domain\Entity\Invoice, created: bool}
     */
    private function create(
        ?DateTimeImmutable $issuedAt = null,
        ?Money $total = null,
        ?Uuid $serviceId = null,
        ?Uuid $providerId = null,
        ?Uuid $documentId = null,
        ?string $number = null,
        ?DateTimeImmutable $periodStart = null,
        ?DateTimeImmutable $periodEnd = null,
        ?DateTimeImmutable $paidAt = null,
        InvoiceStatus $status = InvoiceStatus::UNKNOWN,
        InvoiceSource $source = InvoiceSource::MANUAL,
    ): array {
        $useCase = new CreateInvoice(
            $this->invoices,
            $this->documents,
            $this->services,
            $this->tenantContext,
        );

        return $useCase(new InvoiceInput(
            issuedAt: $issuedAt ?? new DateTimeImmutable('2026-10-03'),
            total: $total ?? Money::fromDecimalString('29,90', Currency::EUR),
            serviceId: $serviceId,
            providerId: $providerId,
            documentId: $documentId,
            number: $number,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            paidAt: $paidAt,
            status: $status,
            source: $source,
        ));
    }

    private function service(): Service
    {
        return new Service(
            organizationId: $this->organizationId,
            name: 'OVH VPS',
            currency: Currency::EUR,
            billingPeriod: BillingPeriod::MONTHLY,
            source: ServiceSource::MANUAL,
        );
    }

    private function document(): Document
    {
        return new Document(
            organizationId: $this->organizationId,
            originalFilename: 'factura.pdf',
            storageDriver: 'memory',
            storageKey: 'org/ab/cd/hash.pdf',
            mimeType: 'application/pdf',
            sizeBytes: 10,
            checksumSha256: str_repeat('a', 64),
            type: DocumentType::INVOICE,
            source: DocumentSource::MANUAL_UPLOAD,
        );
    }
}
