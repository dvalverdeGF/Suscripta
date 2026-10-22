<?php

declare(strict_types=1);

namespace App\Tests\Unit\Privacy;

use App\Privacy\Application\PurgeExpiredData;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\Privacy\RetentionPurgerInterface;
use App\Shared\Domain\Enum\AuditAction;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * La política de retención: purgar lo que ha caducado.
 *
 * Cada purificador declara la **categoría de dato** a la que pertenece, y el
 * informe dice qué se ha borrado y por qué. Que la categoría sea la unidad (y
 * no el módulo) es lo que permite purgar el correo antes que los servicios.
 */
#[CoversClass(PurgeExpiredData::class)]
final class PurgeExpiredDataTest extends TestCase
{
    /** @var list<AuditAction> */
    private array $audited = [];

    public function testEachCategoryIsPurgedAndCounted(): void
    {
        $report = ($this->purger([
            $this->purgerFor('email_messages', 12),
            $this->purgerFor('services', 0),
        ]))();

        self::assertSame(['email_messages' => 12, 'services' => 0], $report->removedByCategory);
        self::assertSame(12, $report->total());
        self::assertFalse($report->isEmpty());
    }

    public function testThePurgersAreGivenTheCurrentTime(): void
    {
        $received = null;

        $purger = $this->createMock(RetentionPurgerInterface::class);
        $purger->method('category')->willReturn('email_messages');
        $purger->method('purge')->willReturnCallback(static function (DateTimeImmutable $now) use (&$received): int {
            $received = $now;

            return 0;
        });

        ($this->purger([$purger]))();

        self::assertInstanceOf(DateTimeImmutable::class, $received);
        self::assertSame('2026-10-05T10:00:00+00:00', $received->format('c'));
    }

    public function testThePurgeIsAudited(): void
    {
        ($this->purger([$this->purgerFor('email_messages', 3)]))();

        self::assertSame([AuditAction::DATA_PURGED], $this->audited);
    }

    public function testAPurgeThatRemovedNothingIsAnEmptyReport(): void
    {
        $report = ($this->purger([$this->purgerFor('email_messages', 0)]))();

        self::assertTrue($report->isEmpty());
    }

    /**
     * @param list<RetentionPurgerInterface> $purgers
     */
    private function purger(array $purgers): PurgeExpiredData
    {
        $this->audited = [];

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger
            ->method('log')
            ->willReturnCallback(function (AuditAction $action): void {
                $this->audited[] = $action;
            });

        return new PurgeExpiredData(
            $purgers,
            $auditLogger,
            new Clock(new MockClock(new DateTimeImmutable('2026-10-05 10:00:00'))),
        );
    }

    private function purgerFor(string $category, int $removed): RetentionPurgerInterface
    {
        $purger = $this->createMock(RetentionPurgerInterface::class);
        $purger->method('category')->willReturn($category);
        $purger->method('purge')->willReturn($removed);

        return $purger;
    }
}
