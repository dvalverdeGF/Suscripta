<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Infrastructure\Ocr;

use App\Processing\Infrastructure\Ocr\TesseractOcrEngine;
use App\Tests\Support\Processing\PdfFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(TesseractOcrEngine::class)]
final class TesseractOcrEngineTest extends TestCase
{
    private LockFactory $locks;

    protected function setUp(): void
    {
        $this->locks = new LockFactory(new InMemoryStore());
    }

    public function testItIsAvailableWhenBothBinariesExist(): void
    {
        // `is_executable()` no busca en PATH, así que la comprobación tiene que
        // pasar por `ExecutableFinder`. Si alguien vuelve a usar `is_executable`
        // con el nombre pelado, este test falla en el contenedor.
        $engine = new TesseractOcrEngine($this->locks);

        self::assertTrue($engine->isAvailable());
    }

    public function testItIsNotAvailableWhenTheBinaryIsMissing(): void
    {
        $engine = new TesseractOcrEngine($this->locks, binary: '/no/existe/tesseract');

        self::assertFalse($engine->isAvailable());
    }

    public function testItIsNotAvailableWhenTheRasterizerIsMissing(): void
    {
        $engine = new TesseractOcrEngine($this->locks, rasterizer: '/no/existe/pdftoppm');

        self::assertFalse($engine->isAvailable());
    }

    public function testItClaimsPdfsAndImages(): void
    {
        $engine = new TesseractOcrEngine($this->locks);

        self::assertTrue($engine->supports('application/pdf', 'factura.pdf'));
        self::assertTrue($engine->supports('image/png', 'foto.png'));
        self::assertTrue($engine->supports('image/jpeg', 'foto.jpg'));
        self::assertTrue($engine->supports('application/octet-stream', 'escaneo.TIFF'));
    }

    public function testItDoesNotClaimTextFormats(): void
    {
        $engine = new TesseractOcrEngine($this->locks);

        self::assertFalse($engine->supports('text/plain', 'notas.txt'));
        self::assertFalse($engine->supports('application/zip', 'paquete.zip'));
    }

    public function testItReturnsNullWhenItIsNotAvailable(): void
    {
        $engine = new TesseractOcrEngine($this->locks, binary: '/no/existe/tesseract');

        self::assertNull($engine->extract('x', 'application/pdf', 'factura.pdf'));
    }

    public function testItReturnsNullForAFormatItDoesNotHandle(): void
    {
        $engine = new TesseractOcrEngine($this->locks);

        self::assertNull($engine->extract('hola', 'text/plain', 'notas.txt'));
    }

    public function testItGivesUpInsteadOfCompetingForTheCpu(): void
    {
        // El roadmap pide concurrencia muy baja para el OCR. Si otro documento
        // se está reconociendo, este no espera: devuelve `null` y el mensaje se
        // reintenta más tarde.
        $engine = new TesseractOcrEngine($this->locks);

        if (!$engine->isAvailable()) {
            self::markTestSkipped('Tesseract no está instalado en este entorno.');
        }

        $held = $this->locks->createLock('suscripta_ocr');
        self::assertTrue($held->acquire());

        try {
            self::assertNull($engine->extract(PdfFixture::withText('Factura'), 'application/pdf', 'factura.pdf'));
        } finally {
            $held->release();
        }
    }

    public function testItRecognisesTheTextOfARasterisedInvoice(): void
    {
        $engine = new TesseractOcrEngine($this->locks);

        if (!$engine->isAvailable()) {
            self::markTestSkipped('Tesseract no está instalado en este entorno.');
        }

        $text = $engine->extract(
            PdfFixture::withText('Factura OVH numero FRA-1 total 29,90 EUR'),
            'application/pdf',
            'factura.pdf',
        );

        self::assertNotNull($text);
        self::assertStringContainsString('FRA-1', $text);
        self::assertStringContainsString('29,90', $text);
    }

    public function testItIdentifiesItself(): void
    {
        self::assertSame('tesseract', (new TesseractOcrEngine($this->locks))->name());
    }
}
