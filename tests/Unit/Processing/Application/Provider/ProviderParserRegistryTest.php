<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Provider;

use App\Processing\Application\Provider\ProviderParserRegistry;
use App\Processing\Domain\Provider\ProviderParseResult;
use App\Processing\Domain\Provider\ProviderParserContext;
use App\Processing\Domain\Provider\ProviderParserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderParserRegistry::class)]
final class ProviderParserRegistryTest extends TestCase
{
    public function testItIndexesParsersByTheirKey(): void
    {
        $registry = new ProviderParserRegistry([$this->parser('ovh'), $this->parser('acme')]);

        self::assertTrue($registry->has('ovh'));
        self::assertTrue($registry->has('acme'));
        self::assertFalse($registry->has('desconocido'));
    }

    public function testItReturnsTheParserForAKey(): void
    {
        $ovh = $this->parser('ovh');
        $registry = new ProviderParserRegistry([$ovh]);

        self::assertSame($ovh, $registry->get('ovh'));
        self::assertNull($registry->get('acme'));
    }

    public function testItListsTheAvailableKeys(): void
    {
        $registry = new ProviderParserRegistry([$this->parser('ovh'), $this->parser('acme')]);

        self::assertSame(['ovh', 'acme'], $registry->keys());
    }

    public function testTheLastParserWinsWhenTwoShareAKey(): void
    {
        $first = $this->parser('ovh');
        $second = $this->parser('ovh');

        $registry = new ProviderParserRegistry([$first, $second]);

        self::assertSame($second, $registry->get('ovh'));
        self::assertSame(['ovh'], $registry->keys());
    }

    public function testItDescribesWhatIsRegistered(): void
    {
        $registry = new ProviderParserRegistry([$this->parser('ovh'), $this->parser('acme')]);

        self::assertSame('Parsers disponibles: ovh, acme.', $registry->describe());
    }

    public function testAnEmptyRegistrySaysSoInsteadOfListingNothing(): void
    {
        $registry = new ProviderParserRegistry([]);

        self::assertSame([], $registry->keys());
        self::assertSame('No hay parsers de código registrados.', $registry->describe());
    }

    private function parser(string $key): ProviderParserInterface
    {
        return new class($key) implements ProviderParserInterface {
            public function __construct(private readonly string $key)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function parse(ProviderParserContext $context): ?ProviderParseResult
            {
                return null;
            }
        };
    }
}
