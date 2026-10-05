<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Matching;

use App\Documents\Domain\Enum\DocumentType;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Processing\Application\Matching\ServiceMatcher;
use App\Processing\Domain\Dto\ExtractedDocument;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;

use function array_column;
use function array_key_exists;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * El emparejamiento es ponderado y explicable, nunca binario: un mismo
 * proveedor puede tener varios servicios y una coincidencia exacta de nombre no
 * siempre significa lo mismo. Y sobre todo: **nunca se crea un servicio
 * automáticamente** (D-35).
 */
#[CoversClass(ServiceMatcher::class)]
final class ServiceMatcherTest extends TestCase
{
    private ServiceRepositoryInterface&MockObject $services;

    protected function setUp(): void
    {
        $this->services = $this->createMock(ServiceRepositoryInterface::class);
    }

    /**
     * @param list<Service> $services
     */
    private function matcher(array $services): ServiceMatcher
    {
        $this->services
            ->method('findForOrganization')
            ->willReturnCallback(static fn (ServiceFilters $filters): array => $services);

        return new ServiceMatcher($this->services);
    }

    private function service(
        string $name,
        Currency $currency = Currency::EUR,
        BillingPeriod $period = BillingPeriod::MONTHLY,
        ?Money $amount = null,
        ?Uuid $providerId = null,
    ): Service {
        $service = new Service(Uuid::v7(), $name, $currency, $period);

        if (null !== $providerId) {
            $service->setProviderId($providerId);
        }

        if (null !== $amount) {
            $service->changePrice($amount, new DateTimeImmutable('2026-01-01'));
        }

        return $service;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function document(array $overrides = []): ExtractedDocument
    {
        $value = static fn (string $key, mixed $default): mixed => array_key_exists($key, $overrides) ? $overrides[$key] : $default;

        return new ExtractedDocument(
            tier: ExtractionTier::DETERMINISTIC,
            confidence: 0.9,
            amountMinor: $value('amountMinor', 2990),
            currency: $value('currency', 'EUR'),
            billingPeriod: $value('billingPeriod', BillingPeriod::MONTHLY),
            sender: $value('sender', 'facturacion@ovh.com'),
            senderDomain: $value('senderDomain', 'ovh.com'),
            subject: 'Factura OVH',
            documentType: DocumentType::INVOICE,
            providerName: $value('providerName', 'OVH'),
            serviceName: $value('serviceName', 'OVH'),
        );
    }

    public function testNoServicesMeansNoMatch(): void
    {
        $result = $this->matcher([])->match($this->document());

        self::assertSame(0, $result->score);
        self::assertNull($result->serviceId);
        self::assertTrue($result->isLowConfidence());
        self::assertStringContainsString('Sin coincidencias', $result->explain());
    }

    public function testAFullMatchIsHighConfidence(): void
    {
        $service = $this->service('OVH', amount: Money::of(2990, Currency::EUR), providerId: Uuid::v7());

        $result = $this->matcher([$service])->match($this->document());

        self::assertSame($service->getId(), $result->serviceId);
        self::assertTrue($result->isHighConfidence());
        self::assertGreaterThanOrEqual(70, $result->score);
    }

    public function testEverySignalIsExplainable(): void
    {
        $service = $this->service('OVH', amount: Money::of(2990, Currency::EUR), providerId: Uuid::v7());

        $result = $this->matcher([$service])->match($this->document());

        $signals = array_column($result->reasonsAsArray(), 'signal');

        self::assertContains('Proveedor identificado', $signals);
        self::assertContains('Dominio del remitente', $signals);
        self::assertContains('Nombre del servicio', $signals);
        self::assertContains('Misma moneda', $signals);
        self::assertContains('Misma periodicidad', $signals);
        self::assertContains('Importe compatible', $signals);
        self::assertStringContainsString('Puntuación', $result->explain());
    }

    public function testTheBestCandidateWins(): void
    {
        $weak = $this->service('Netflix', Currency::USD, BillingPeriod::ANNUAL);
        $strong = $this->service('OVH', amount: Money::of(2990, Currency::EUR), providerId: Uuid::v7());

        $result = $this->matcher([$weak, $strong])->match($this->document());

        self::assertSame($strong->getId(), $result->serviceId);
    }

    /**
     * Una subida de precio sigue siendo el mismo servicio, pero un importe muy
     * distinto suele ser otro plan u otro producto.
     */
    public function testAmountToleranceAcceptsASmallPriceRise(): void
    {
        $service = $this->service('OVH', amount: Money::of(2990, Currency::EUR), providerId: Uuid::v7());

        $result = $this->matcher([$service])->match($this->document(['amountMinor' => 3200]));

        self::assertContains('Importe compatible', array_column($result->reasonsAsArray(), 'signal'));
    }

    public function testAmountToleranceRejectsADifferentProduct(): void
    {
        $service = $this->service('OVH', amount: Money::of(2990, Currency::EUR), providerId: Uuid::v7());

        $result = $this->matcher([$service])->match($this->document(['amountMinor' => 29900]));

        self::assertNotContains('Importe compatible', array_column($result->reasonsAsArray(), 'signal'));
    }

    public function testDomainMatchesOnTheFirstLabel(): void
    {
        $service = $this->service('OVH Hosting');

        $result = $this->matcher([$service])->match($this->document(['providerName' => null, 'serviceName' => null]));

        self::assertContains('Dominio del remitente', array_column($result->reasonsAsArray(), 'signal'));
    }

    public function testAnUnrelatedServiceScoresLow(): void
    {
        $service = $this->service('Netflix', Currency::USD, BillingPeriod::ANNUAL);

        $result = $this->matcher([$service])->match($this->document());

        self::assertTrue($result->isLowConfidence());
        self::assertLessThan(40, $result->score);
    }

    public function testMediumConfidenceIsTheReviewZone(): void
    {
        // Proveedor + dominio = 60: hay un candidato razonable, pero el usuario
        // debe decidir. Es la zona de revisión.
        $service = $this->service('OVH', Currency::USD, BillingPeriod::ANNUAL, providerId: Uuid::v7());

        $result = $this->matcher([$service])->match($this->document(['serviceName' => null]));

        self::assertTrue($result->isMediumConfidence());
        self::assertGreaterThanOrEqual(40, $result->score);
        self::assertLessThan(70, $result->score);
    }

    public function testSenderMatchRequiresTheServiceNameInTheLocalPart(): void
    {
        $service = $this->service('OVH');

        $matching = $this->matcher([$service])->match($this->document(['sender' => 'facturacion@ovh.com']));
        $other = $this->matcher([$service])->match($this->document(['sender' => 'facturacion@otro.com']));

        self::assertContains('Remitente exacto ya visto', array_column($matching->reasonsAsArray(), 'signal'));
        self::assertNotContains('Remitente exacto ya visto', array_column($other->reasonsAsArray(), 'signal'));
    }

    public function testZeroAmountServiceOnlyMatchesAZeroAmountDocument(): void
    {
        $service = $this->service('OVH', amount: Money::of(0, Currency::EUR));

        $zero = $this->matcher([$service])->match($this->document(['amountMinor' => 0]));
        $paid = $this->matcher([$service])->match($this->document(['amountMinor' => 2990]));

        self::assertContains('Importe compatible', array_column($zero->reasonsAsArray(), 'signal'));
        self::assertNotContains('Importe compatible', array_column($paid->reasonsAsArray(), 'signal'));
    }

    public function testScoreIsNeverNegative(): void
    {
        $result = $this->matcher([$this->service('Netflix', Currency::USD, BillingPeriod::ANNUAL)])
            ->match($this->document(['providerName' => null, 'serviceName' => null, 'senderDomain' => null, 'sender' => null]));

        self::assertSame(0, $result->score);
    }
}
