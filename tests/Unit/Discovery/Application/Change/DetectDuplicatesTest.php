<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Application\Change;

use App\Discovery\Application\Change\ChangeSettings;
use App\Discovery\Application\Change\DetectDuplicates;
use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Services\Domain\Dto\ServiceView;
use App\Services\Domain\Enum\ServiceStatus;
use App\Services\Domain\Repository\ServiceViewRepositoryInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Services\ServiceViewBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * El detector de duplicados (Fase 15).
 *
 * Lo que hay que garantizar es que **no decide nada**: propone una sugerencia
 * con las señales que la han-motorizado, y espera a que el usuario decida.
 * También que sea **idempotente**: el barrido se puede lanzar cada noche y no
 * puede preguntar dos veces lo mismo.
 */
final class DetectDuplicatesTest extends TestCase
{
    private const NOW = '2026-10-05 09:00:00';

    private Uuid $organizationId;
    private ServiceViewRepositoryInterface&MockObject $services;
    private DiscoveryRepositoryInterface&MockObject $discoveries;

    /** @var list<Discovery> */
    private array $saved = [];

    /** @var list<ServiceView> */
    private array $views = [];

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->services = $this->createMock(ServiceViewRepositoryInterface::class);
        $this->discoveries = $this->createMock(DiscoveryRepositoryInterface::class);
        $this->saved = [];
        $this->views = [];

        $this->services->method('findAll')->willReturnCallback(fn (): array => $this->views);
        $this->discoveries->method('findByDedupKey')->willReturn(null);
        $this->discoveries->method('save')->willReturnCallback(
            function (Discovery $discovery): void {
                $this->saved[] = $discovery;
            },
        );
    }

    public function testItDoesNotProposeAnythingWhenThereIsOnlyOneService(): void
    {
        $this->views = [ServiceViewBuilder::create('Netflix')->build()];

        $proposals = $this->detect();

        self::assertSame([], $proposals);
    }

    public function testItProposesASuspicionWhenProviderAndAmountMatch(): void
    {
        $provider = Uuid::v7();

        $this->views = [
            ServiceViewBuilder::create('Netflix')
                ->withProvider($provider)
                ->withAmount(Money::of(1299, Currency::EUR))
                ->withCreatedAt(new DateTimeImmutable('2025-01-01 00:00:00'))
                ->build(),
            ServiceViewBuilder::create('Netflix Familiar')
                ->withProvider($provider)
                ->withAmount(Money::of(1299, Currency::EUR))
                ->withCreatedAt(new DateTimeImmutable('2026-02-01 00:00:00'))
                ->build(),
        ];

        $proposals = $this->detect();

        self::assertCount(1, $proposals);
        self::assertSame(DiscoveryType::DUPLICATE, $proposals[0]->getType());
        self::assertSame($proposals, $this->saved, 'La sospecha queda guardada para que el usuario la revise.');
    }

    public function testTheSuspicionExplainsWhichSignalsMatched(): void
    {
        $provider = Uuid::v7();

        $this->views = [
            ServiceViewBuilder::create('Netflix')
                ->withProvider($provider)
                ->withAmount(Money::of(1299, Currency::EUR))
                ->build(),
            ServiceViewBuilder::create('Netflix')
                ->withProvider($provider)
                ->withAmount(Money::of(1299, Currency::EUR))
                ->build(),
        ];

        $proposals = $this->detect();
        $signals = array_map(static fn (array $reason): string => $reason['signal'], $proposals[0]->getMatchReasons());

        self::assertContains('Mismo proveedor', $signals);
        self::assertContains('Mismo importe', $signals);
    }

    public function testTheOldestServiceIsTreatedAsTheOriginal(): void
    {
        $provider = Uuid::v7();
        $old = ServiceViewBuilder::create('Netflix')->withId(Uuid::v7())
            ->withProvider($provider)
            ->withAmount(Money::of(1299, Currency::EUR))
            ->withCreatedAt(new DateTimeImmutable('2025-01-01 00:00:00'))
            ->build();
        $new = ServiceViewBuilder::create('Netflix')->withId(Uuid::v7())
            ->withProvider($provider)
            ->withAmount(Money::of(1299, Currency::EUR))
            ->withCreatedAt(new DateTimeImmutable('2026-02-01 00:00:00'))
            ->build();

        $this->views = [$new, $old];

        $proposals = $this->detect();
        $proposed = $proposals[0]->getProposedData();

        self::assertSame($old->id->toRfc4122(), $proposed['serviceId']);
        self::assertSame($new->id->toRfc4122(), $proposed['relatedServiceId']);
        self::assertTrue($old->id->equals($proposals[0]->getMatchedServiceId()));
    }

    public function testASuspicionIsAlwaysMarkedAsAnInference(): void
    {
        $provider = Uuid::v7();

        $this->views = [
            ServiceViewBuilder::create('Netflix')->withProvider($provider)->withAmount(Money::of(1299, Currency::EUR))->build(),
            ServiceViewBuilder::create('Netflix')->withProvider($provider)->withAmount(Money::of(1299, Currency::EUR))->build(),
        ];

        $proposals = $this->detect();

        self::assertTrue($proposals[0]->isInference());
        self::assertTrue(DiscoveryType::DUPLICATE->isInference());
    }

    public function testTheConfidenceOfASuspicionNeverExceedsTheLowBand(): void
    {
        $provider = Uuid::v7();

        $this->views = [
            ServiceViewBuilder::create('Netflix')->withProvider($provider)->withAmount(Money::of(1299, Currency::EUR))->build(),
            ServiceViewBuilder::create('Netflix')->withProvider($provider)->withAmount(Money::of(1299, Currency::EUR))->build(),
        ];

        $proposals = $this->detect();

        // Aunque el detector esté seguro, la banda baja es el techo: el
        // usuario no ha visto ningún documento que lo diga (D-59).
        self::assertLessThanOrEqual(54, $proposals[0]->getConfidenceScore());
    }

    public function testItIgnoresServicesThatAreNotActive(): void
    {
        $provider = Uuid::v7();

        $this->views = [
            ServiceViewBuilder::create('Netflix')->withProvider($provider)->withAmount(Money::of(1299, Currency::EUR))->build(),
            ServiceViewBuilder::create('Netflix')->withProvider($provider)->withAmount(Money::of(1299, Currency::EUR))
                ->withStatus(ServiceStatus::CANCELLED)
                ->build(),
        ];

        self::assertSame([], $this->detect());
    }

    public function testSimilarNamesAloneAreNotEnough(): void
    {
        // Mismo nombre, distinto proveedor y distinto importe: 40 puntos de
        // similitud, muy por debajo del umbral de 70.
        $this->views = [
            ServiceViewBuilder::create('Netflix')->withProvider(Uuid::v7())->withAmount(Money::of(1299, Currency::EUR))->build(),
            ServiceViewBuilder::create('Netflix')->withProvider(Uuid::v7())->withAmount(Money::of(1599, Currency::EUR))->build(),
        ];

        self::assertSame([], $this->detect());
    }

    public function testItDoesNotProposeTheSamePairTwice(): void
    {
        $provider = Uuid::v7();

        $this->views = [
            ServiceViewBuilder::create('Netflix')->withProvider($provider)->withAmount(Money::of(1299, Currency::EUR))->build(),
            ServiceViewBuilder::create('Netflix')->withProvider($provider)->withAmount(Money::of(1299, Currency::EUR))->build(),
        ];

        $first = $this->detect();

        self::assertCount(1, $first);

        // El barrido se puede lanzar cada noche: si ya se propuso esta pareja,
        // no se vuelve a preguntar.
        $this->discoveries = $this->createMock(DiscoveryRepositoryInterface::class);
        $this->discoveries->method('findByDedupKey')->willReturn($first[0]);
        $this->discoveries->method('save')->willReturnCallback(
            function (Discovery $discovery): void {
                $this->saved[] = $discovery;
            },
        );

        self::assertSame([], $this->detect());
    }

    public function testItIgnoresSmallAmountDifferences(): void
    {
        // Dos céntimos de diferencia no son una discrepancia: entra en la
        // tolerancia.
        $provider = Uuid::v7();

        $this->views = [
            ServiceViewBuilder::create('Netflix')->withProvider($provider)->withAmount(Money::of(1299, Currency::EUR))->build(),
            ServiceViewBuilder::create('Netflix')->withProvider($provider)->withAmount(Money::of(1301, Currency::EUR))->build(),
        ];

        self::assertCount(1, $this->detect());
    }

    /**
     * @return list<Discovery>
     */
    private function detect(): array
    {
        $detector = new DetectDuplicates(
            services: $this->services,
            discoveries: $this->discoveries,
            settings: new ChangeSettings(),
            tenantContext: $this->tenantContext(),
            clock: new Clock(new MockClock(new DateTimeImmutable(self::NOW))),
        );

        return $detector->detect();
    }

    private function tenantContext(): TenantContext
    {
        $context = new TenantContext();
        $context->setOrganizationId($this->organizationId);

        return $context;
    }
}
