<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Text;

use App\Processing\Application\Text\DocumentTextExtractorRegistry;
use App\Processing\Domain\Text\DocumentTextExtractorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DocumentTextExtractorRegistry::class)]
final class DocumentTextExtractorRegistryTest extends TestCase
{
    public function testItAsksTheHighestPriorityExtractorFirst(): void
    {
        // El orden no puede depender del orden en que el contenedor descubre los
        // servicios: si el extractor genérico fuera primero, un PDF se leería
        // como texto plano y el pipeline perdería el contenido.
        $registry = new DocumentTextExtractorRegistry([
            $this->extractor('low', 0, 'texto genérico'),
            $this->extractor('high', 100, 'texto específico'),
        ]);

        $result = $registry->extract('contenido', 'application/pdf', 'factura.pdf');

        self::assertNotNull($result);
        self::assertSame('high', $result['extractor']);
        self::assertSame('texto específico', $result['text']);
    }

    public function testItFallsThroughToTheNextExtractorWhenOneDeclines(): void
    {
        $registry = new DocumentTextExtractorRegistry([
            $this->extractor('high', 100, null),
            $this->extractor('low', 0, 'texto genérico'),
        ]);

        $result = $registry->extract('contenido', 'text/plain', 'notas.txt');

        self::assertNotNull($result);
        self::assertSame('low', $result['extractor']);
    }

    public function testItReturnsNullWhenNobodyClaimsTheFormat(): void
    {
        $registry = new DocumentTextExtractorRegistry([$this->extractor('only', 10, null)]);

        self::assertNull($registry->extract('contenido', 'application/zip', 'paquete.zip'));
    }

    public function testItReportsWhetherAnyExtractorClaimsAFormat(): void
    {
        $claiming = new DocumentTextExtractorRegistry([$this->extractor('only', 10, 'x')]);
        $declining = new DocumentTextExtractorRegistry([$this->extractor('only', 10, 'x', claims: false)]);

        self::assertTrue($claiming->supports('application/pdf', 'factura.pdf'));
        self::assertFalse($declining->supports('application/pdf', 'factura.pdf'));
    }

    public function testItListsEveryExtractor(): void
    {
        $registry = new DocumentTextExtractorRegistry([
            $this->extractor('a', 10, 'x'),
            $this->extractor('b', 20, 'y'),
        ]);

        self::assertCount(2, $registry->all());
    }

    public function testAnEmptyRegistryIsHarmless(): void
    {
        $registry = new DocumentTextExtractorRegistry([]);

        self::assertSame([], $registry->all());
        self::assertNull($registry->extract('x', 'application/pdf', 'factura.pdf'));
        self::assertFalse($registry->supports('application/pdf', 'factura.pdf'));
    }

    /**
     * @return DocumentTextExtractorInterface&object{name: string}
     */
    private function extractor(string $name, int $priority, ?string $text, bool $claims = true): DocumentTextExtractorInterface
    {
        return new class($name, $priority, $text, $claims) implements DocumentTextExtractorInterface {
            public function __construct(
                private readonly string $identifier,
                private readonly int $order,
                private readonly ?string $text,
                private readonly bool $claims,
            ) {
            }

            public function name(): string
            {
                return $this->identifier;
            }

            public function priority(): int
            {
                return $this->order;
            }

            public function supports(string $mimeType, string $filename): bool
            {
                return $this->claims;
            }

            public function extract(string $contents, string $mimeType, string $filename): ?string
            {
                return $this->text;
            }
        };
    }
}
