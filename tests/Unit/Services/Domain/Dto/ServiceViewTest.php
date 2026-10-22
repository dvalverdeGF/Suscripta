<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Domain\Dto;

use App\Services\Domain\Dto\ServiceEventView;
use App\Services\Domain\Dto\ServicePriceView;
use App\Services\Domain\Dto\ServiceView;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Enum\ServiceEventType;
use App\Services\Domain\Enum\ServiceSource;
use App\Services\Domain\Enum\ServiceStatus;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * El read model de `Services` es la frontera que permite que el resto de la
 * aplicación lea el inventario sin conocer la entidad.
 *
 * Estas pruebas fijan las tres propiedades de las que depende todo lo demás:
 *
 * - **fidelidad**: la vista refleja el estado de la entidad en el momento de
 *   construirla;
 * - **identidad de objeto**: `currentPrice` es la *misma instancia* que
 *   aparece en `prices`, para poder preguntar «¿cuál es el vigente?» con `===`
 *   y no comparando importes;
 * - **solo lectura**: la vista es un snapshot, así que nadie puede escribir a
 *   través de ella.
 */
#[CoversClass(ServiceView::class)]
#[CoversClass(ServicePriceView::class)]
#[CoversClass(ServiceEventView::class)]
final class ServiceViewTest extends TestCase
{
    private Uuid $organizationId;

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
    }

    public function testItMirrorsTheStateOfTheService(): void
    {
        $service = new Service($this->organizationId, 'OVH VPS', Currency::EUR, BillingPeriod::MONTHLY);
        $service->setPlanName('VPS-2');
        $service->setProviderId(Uuid::v7());
        $service->setCategoryId(Uuid::v7());
        $service->setNextChargeAt(new DateTimeImmutable('2026-10-20'));
        $service->setPaymentMethodLabel('Tarjeta');
        $service->setNotes('Factura mensual');

        $view = ServiceView::fromService($service);

        self::assertSame($service->getId(), $view->id);
        self::assertSame('OVH VPS', $view->name);
        self::assertSame('VPS-2', $view->planName);
        self::assertSame(ServiceStatus::ACTIVE, $view->status);
        self::assertSame(ServiceSource::MANUAL, $view->source);
        self::assertSame(Currency::EUR, $view->currency);
        self::assertSame(BillingPeriod::MONTHLY, $view->billingPeriod);
        self::assertSame(1, $view->billingIntervalCount);
        self::assertTrue($view->providerId?->equals($service->getProviderId() ?? Uuid::v7()));
        self::assertTrue($view->categoryId?->equals($service->getCategoryId() ?? Uuid::v7()));
        self::assertSame('Tarjeta', $view->paymentMethodLabel);
        self::assertSame('Factura mensual', $view->notes);
        self::assertSame('2026-10-20', $view->nextChargeAt?->format('Y-m-d'));
    }

    public function testAServiceWithoutPricesHasNoCurrentPrice(): void
    {
        $view = ServiceView::fromService(
            new Service($this->organizationId, 'Sin precio', Currency::EUR, BillingPeriod::MONTHLY),
        );

        self::assertNull($view->currentPrice);
        self::assertNull($view->currentAmount);
        self::assertSame([], $view->prices);
    }

    public function testTheCurrentPriceIsTheSameInstanceAsTheOneInTheHistory(): void
    {
        $service = $this->service();
        $service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-01'));
        $service->changePrice(Money::of(1200, Currency::EUR), new DateTimeImmutable('2026-06-01'));

        $view = ServiceView::fromService($service);

        self::assertNotNull($view->currentPrice);
        self::assertSame(1200, $view->currentPrice->amount->amountMinor);
        self::assertSame($view->currentPrice, $view->prices[1]);
        self::assertTrue($view->currentPrice->isCurrent);
        self::assertSame(1200, $view->currentAmount?->amountMinor);
    }

    public function testItFindsThePriceThatWasInForceOnAGivenDate(): void
    {
        $service = $this->service();
        $service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-01'));
        $service->changePrice(Money::of(1200, Currency::EUR), new DateTimeImmutable('2026-06-01'));

        $view = ServiceView::fromService($service);

        $before = $view->priceAt(new DateTimeImmutable('2026-03-15'));
        $after = $view->priceAt(new DateTimeImmutable('2026-08-15'));
        $beforeItStarted = $view->priceAt(new DateTimeImmutable('2025-12-31'));

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertSame(1000, $before->amount->amountMinor);
        self::assertSame(1200, $after->amount->amountMinor);
        self::assertNull($beforeItStarted, 'Antes del primer precio no hay histórico que mostrar.');
    }

    public function testAPriceCoversOnlyItsOwnPeriod(): void
    {
        $service = $this->service();
        $service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-01'));
        $service->changePrice(Money::of(1200, Currency::EUR), new DateTimeImmutable('2026-06-01'));

        $view = ServiceView::fromService($service);
        $first = $view->prices[0];
        $second = $view->prices[1];

        self::assertTrue($first->covers(new DateTimeImmutable('2026-01-01')));
        self::assertTrue($first->covers(new DateTimeImmutable('2026-05-31')));
        self::assertFalse($first->covers(new DateTimeImmutable('2026-06-01')));

        self::assertTrue($second->covers(new DateTimeImmutable('2026-06-01')));
        self::assertFalse($second->covers(new DateTimeImmutable('2026-05-31')));
    }

    public function testTheNoticeDeadlineIsTheRenewalMinusTheNoticePeriod(): void
    {
        $service = $this->service();
        $service->setRenewalAt(new DateTimeImmutable('2026-12-01'));
        $service->setNoticePeriodDays(30);

        $view = ServiceView::fromService($service);

        self::assertSame('2026-11-01', $view->noticeDeadline?->format('Y-m-d'));
    }

    public function testThereIsNoNoticeDeadlineWithoutARenewalOrWithoutANoticePeriod(): void
    {
        $service = $this->service();
        $service->setNoticePeriodDays(30);

        self::assertNull(ServiceView::fromService($service)->noticeDeadline);

        $service->setRenewalAt(new DateTimeImmutable('2026-12-01'));
        $service->setNoticePeriodDays(null);

        self::assertNull(ServiceView::fromService($service)->noticeDeadline);
    }

    public function testItExposesTheEventsOfTheService(): void
    {
        $service = $this->service();
        $service->changePrice(Money::of(1000, Currency::EUR), new DateTimeImmutable('2026-01-01'));
        $service->pause();

        $view = ServiceView::fromService($service);

        $paused = $view->eventsOfType(ServiceEventType::PAUSED);

        self::assertCount(1, $paused);
        self::assertInstanceOf(ServiceEventView::class, $paused[0]);
        self::assertSame(ServiceEventType::PAUSED, $paused[0]->type);
        self::assertSame([], $view->eventsOfType(ServiceEventType::RESUMED));
    }

    public function testTheViewIsASnapshotAndDoesNotSeeLaterChanges(): void
    {
        $service = $this->service();

        $view = ServiceView::fromService($service);

        // La vista no da acceso a la entidad: cambiar la entidad después no la
        // altera. Por eso nadie puede escribir a través del modelo de lectura.
        $service->rename('Nombre nuevo');

        self::assertSame('OVH VPS', $view->name);
    }

    private function service(): Service
    {
        return new Service($this->organizationId, 'OVH VPS', Currency::EUR, BillingPeriod::MONTHLY);
    }
}
