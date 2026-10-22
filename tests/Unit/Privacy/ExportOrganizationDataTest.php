<?php

declare(strict_types=1);

namespace App\Tests\Unit\Privacy;

use App\Privacy\Application\ExportOrganizationData;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\Privacy\OrganizationDataProviderInterface;
use App\Shared\Domain\Enum\AuditAction;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * La exportación de los datos de una organización (derecho de acceso).
 *
 * Lo que se comprueba aquí es el **contrato** del agregador: que junta las
 * secciones que publica cada módulo, que las ordena y que deja constancia
 * en la auditoría. Que un módulo exporte bien sus datos es cosa de su
 * propio test (ARCHITECTURE.md §13.17).
 */
#[CoversClass(ExportOrganizationData::class)]
final class ExportOrganizationDataTest extends TestCase
{
    private Uuid $organizationId;

    /** @var list<AuditAction> */
    private array $audited = [];

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->audited = [];
    }

    public function testItJoinsEverySectionTheModulesPublish(): void
    {
        $export = ($this->exporter([
            $this->provider('services', ['rows' => [['name' => 'Netflix']]]),
            $this->provider('documents', ['rows' => []]),
        ]))($this->organizationId);

        self::assertSame(['documents', 'services'], $export->sectionNames());
        self::assertSame([['name' => 'Netflix']], $export->sections['services']['rows']);
    }

    public function testTheSectionsAreSortedByName(): void
    {
        $export = ($this->exporter([
            $this->provider('zeta', []),
            $this->provider('alpha', []),
            $this->provider('mid', []),
        ]))($this->organizationId);

        self::assertSame(['alpha', 'mid', 'zeta'], $export->sectionNames());
    }

    public function testItCountsEveryRowOfEverySection(): void
    {
        $export = ($this->exporter([
            $this->provider('services', ['rows' => [['name' => 'Netflix'], ['name' => 'Spotify']]]),
            $this->provider('documents', ['rows' => [['name' => 'factura.pdf']]]),
            $this->provider('identity', ['meta' => ['name' => 'Ada']]),
        ]))($this->organizationId);

        self::assertSame(3, $export->rowCount());
    }

    public function testItIsAuditedWithWhatWasExported(): void
    {
        ($this->exporter([
            $this->provider('services', ['rows' => [['name' => 'Netflix']]]),
        ]))($this->organizationId);

        self::assertSame([AuditAction::DATA_EXPORTED], $this->audited);
    }

    public function testTheExportIsStampedWithTheClock(): void
    {
        $export = ($this->exporter([]))($this->organizationId);

        self::assertSame('2026-10-05T10:00:00+00:00', $export->generatedAt->format('c'));
    }

    /**
     * @param list<OrganizationDataProviderInterface> $providers
     */
    private function exporter(array $providers): ExportOrganizationData
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger
            ->method('log')
            ->willReturnCallback(function (AuditAction $action): void {
                $this->audited[] = $action;
            });

        return new ExportOrganizationData(
            $providers,
            $auditLogger,
            new Clock(new MockClock(new DateTimeImmutable('2026-10-05 10:00:00'))),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function provider(string $section, array $payload): OrganizationDataProviderInterface
    {
        $provider = $this->createMock(OrganizationDataProviderInterface::class);
        $provider->method('section')->willReturn($section);
        $provider->method('export')->willReturn($payload);

        return $provider;
    }
}
