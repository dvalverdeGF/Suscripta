<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Infrastructure\Ocr;

use App\Processing\Infrastructure\Ocr\NullOcrEngine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NullOcrEngine::class)]
final class NullOcrEngineTest extends TestCase
{
    private NullOcrEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new NullOcrEngine();
    }

    public function testItIsNeverAvailable(): void
    {
        // Es la degradación explícita: un entorno sin Tesseract no debe
        // comportarse como si lo tuviera y devolver texto inventado.
        self::assertFalse($this->engine->isAvailable());
    }

    public function testItClaimsNoFormat(): void
    {
        self::assertFalse($this->engine->supports('application/pdf', 'factura.pdf'));
        self::assertFalse($this->engine->supports('image/png', 'foto.png'));
    }

    public function testItReturnsNoText(): void
    {
        self::assertNull($this->engine->extract('x', 'application/pdf', 'factura.pdf'));
    }

    public function testItIdentifiesItself(): void
    {
        self::assertSame('none', $this->engine->name());
    }
}
