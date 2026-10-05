<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application;

use App\Shared\Application\TenantContext;
use App\Shared\Application\TenantFilterSynchronizerInterface;
use App\Shared\Domain\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Cambiar de organización tiene que surtir efecto en las consultas.
 *
 * El filtro de Doctrine no se entera de que el contexto ha cambiado: hay que
 * publicárselo. Si esto se rompe, un comando que recorre organizaciones lee los
 * datos de todas y genera avisos cruzados, que es el peor fallo posible en un
 * producto multi-tenant.
 */
#[CoversClass(TenantContext::class)]
final class TenantContextTest extends TestCase
{
    public function testItStartsWithoutOrganization(): void
    {
        $context = new TenantContext();

        self::assertNull($context->getOrganizationId());
        self::assertFalse($context->hasOrganization());
    }

    public function testItPublishesTheOrganizationWhenItChanges(): void
    {
        $synchronizer = new RecordingTenantFilterSynchronizer();
        $context = new TenantContext($synchronizer);
        $organizationId = Uuid::v7();

        $context->setOrganizationId($organizationId);

        self::assertSame([$organizationId->toRfc4122()], $synchronizer->published);
    }

    public function testItPublishesTheAbsenceOfOrganization(): void
    {
        $synchronizer = new RecordingTenantFilterSynchronizer();
        $context = new TenantContext($synchronizer);

        $organizationId = Uuid::v7();

        $context->setOrganizationId($organizationId);
        $context->setOrganizationId(null);

        self::assertSame([$organizationId->toRfc4122(), null], $synchronizer->published);
    }

    public function testRunAsPublishesTheOrganizationAndRestoresThePreviousOne(): void
    {
        $synchronizer = new RecordingTenantFilterSynchronizer();
        $context = new TenantContext($synchronizer);
        $first = Uuid::v7();
        $second = Uuid::v7();

        $context->setOrganizationId($first);
        $synchronizer->published = [];

        $seen = $context->runAs($second, static fn (): ?Uuid => $context->getOrganizationId());

        self::assertSame($second->toRfc4122(), $seen?->toRfc4122());
        self::assertSame($first->toRfc4122(), $context->getOrganizationId()?->toRfc4122());
        self::assertSame(
            [$second->toRfc4122(), $first->toRfc4122()],
            $synchronizer->published,
        );
    }

    public function testRunAsRestoresTheContextEvenWhenTheBlockFails(): void
    {
        $synchronizer = new RecordingTenantFilterSynchronizer();
        $context = new TenantContext($synchronizer);
        $first = Uuid::v7();

        $context->setOrganizationId($first);

        try {
            $context->runAs(Uuid::v7(), static function (): void {
                throw new InvalidArgumentException('Falla a propósito.');
            });
            self::fail('El bloque debería haber lanzado.');
        } catch (InvalidArgumentException) {
            // Esperado.
        }

        self::assertSame($first->toRfc4122(), $context->getOrganizationId()?->toRfc4122());
    }

    public function testItWorksWithoutASynchronizer(): void
    {
        $context = new TenantContext();
        $organizationId = Uuid::v7();

        $context->setOrganizationId($organizationId);

        self::assertSame($organizationId->toRfc4122(), $context->getOrganizationId()?->toRfc4122());
    }

    public function testRequireOrganizationIdFailsWithoutOrganization(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TenantContext()->requireOrganizationId();
    }
}

/**
 * Doble que anota qué organización se ha publicado en el filtro.
 */
final class RecordingTenantFilterSynchronizer implements TenantFilterSynchronizerInterface
{
    /** @var list<?string> */
    public array $published = [];

    public function synchronize(?Uuid $organizationId): void
    {
        $this->published[] = $organizationId?->toRfc4122();
    }
}
