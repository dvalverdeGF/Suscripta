<?php

declare(strict_types=1);

namespace App\Tests\Unit\Privacy;

use App\Privacy\Application\DeleteOrganizationData;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Privacy\OrganizationDataRemoverInterface;
use App\Shared\Domain\Enum\AuditAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * El borrado de los datos de una organización (derecho de supresión).
 *
 * Lo que importa aquí es el **orden**: cada módulo dice cuándo puede irse y
 * el agregador lo respeta, porque un borrado en el orden equivocado deja
 * filas apuntando a cosas que ya no existen. Y que la constancia del borrado
 * quede escrita, porque el borrado también tiene que dar fe.
 */
#[CoversClass(DeleteOrganizationData::class)]
final class DeleteOrganizationDataTest extends TestCase
{
    private Uuid $organizationId;

    /** @var list<AuditAction> */
    private array $audited = [];

    protected function setUp(): void
    {
        $this->organizationId = Uuid::v7();
        $this->audited = [];
    }

    public function testEveryModuleRemovesItsOwnData(): void
    {
        $report = ($this->deleter([
            $this->remover('mailbox', 70, 3),
            $this->remover('identity', 90, 1),
        ]))($this->organizationId);

        self::assertSame(['identity' => 1, 'mailbox' => 3], $report->removedByModule);
        self::assertSame(4, $report->total());
        self::assertFalse($report->isEmpty());
    }

    public function testTheModulesAreRemovedInPriorityOrder(): void
    {
        $calls = [];

        $remover = $this->createMock(OrganizationDataRemoverInterface::class);
        $remover->method('module')->willReturn('servicios');
        $remover->method('priority')->willReturn(10);
        $remover->method('removeForOrganization')->willReturnCallback(
            static function (Uuid $organizationId) use (&$calls): int {
                $calls[] = 'servicios';

                return 0;
            },
        );

        $late = $this->createMock(OrganizationDataRemoverInterface::class);
        $late->method('module')->willReturn('identidad');
        $late->method('priority')->willReturn(90);
        $late->method('removeForOrganization')->willReturnCallback(
            static function (Uuid $organizationId) use (&$calls): int {
                $calls[] = 'identidad';

                return 0;
            },
        );

        ($this->deleter([$late, $remover]))($this->organizationId);

        self::assertSame(['servicios', 'identidad'], $calls);
    }

    public function testAnOrganizationWithNoDataProducesAnEmptyReport(): void
    {
        $report = ($this->deleter([$this->remover('mailbox', 70, 0)]))($this->organizationId);

        self::assertTrue($report->isEmpty());
        self::assertSame(0, $report->total());
    }

    public function testTheDeletionIsAuditedSoTheProofSurvivesTheDeletion(): void
    {
        ($this->deleter([$this->remover('mailbox', 70, 5)]))($this->organizationId);

        self::assertSame([AuditAction::DATA_PURGED], $this->audited);
    }

    /**
     * @param list<OrganizationDataRemoverInterface> $removers
     */
    private function deleter(array $removers): DeleteOrganizationData
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger
            ->method('log')
            ->willReturnCallback(function (AuditAction $action): void {
                $this->audited[] = $action;
            });

        return new DeleteOrganizationData(
            $removers,
            $auditLogger,
        );
    }

    private function remover(string $module, int $priority, int $removed): OrganizationDataRemoverInterface
    {
        $remover = $this->createMock(OrganizationDataRemoverInterface::class);
        $remover->method('module')->willReturn($module);
        $remover->method('priority')->willReturn($priority);
        $remover->method('removeForOrganization')->willReturn($removed);

        return $remover;
    }
}
