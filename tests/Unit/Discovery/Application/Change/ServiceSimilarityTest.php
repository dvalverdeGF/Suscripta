<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery\Application\Change;

use App\Discovery\Application\Change\ServiceSimilarity;
use PHPUnit\Framework\TestCase;

/**
 * La comparación de nombres es la pieza más traicionera de la detección de
 * duplicados: si normaliza mal, el detector o bien no ve los duplicados
 *-evidentes o bien marca como duplicados servicios que no lo son.
 */
final class ServiceSimilarityTest extends TestCase
{
    public function testTheSameNameIsFullySimilar(): void
    {
        self::assertSame(100, ServiceSimilarity::similarity('Netflix', 'Netflix'));
    }

    public function testItIgnoresCaseAndAccents(): void
    {
        self::assertSame(100, ServiceSimilarity::similarity('Canción de Pago', 'cancion de pago'));
    }

    public function testItIgnoresPunctuation(): void
    {
        self::assertSame(100, ServiceSimilarity::similarity('Acme Cloud — Plan Pro', 'acme cloud plan pro'));
    }

    public function testDifferentNamesAreNotSimilar(): void
    {
        self::assertLessThan(50, ServiceSimilarity::similarity('Netflix', 'Spotify'));
    }

    public function testAnExtraSuffixReducesTheSimilarity(): void
    {
        // «Netflix» y «Netflix Business» no son el mismo servicio: el sufijo
        // tiene que bajar la similitud por debajo del umbral del detector.
        self::assertLessThan(85, ServiceSimilarity::similarity('Netflix', 'Netflix Business'));
    }

    public function testAnEmptyNameIsNeverSimilar(): void
    {
        self::assertSame(0, ServiceSimilarity::similarity('', 'Netflix'));
        self::assertSame(0, ServiceSimilarity::similarity('   ', 'Netflix'));
    }

    public function testNormalizeRemovesEverythingThatIsNotAlphanumeric(): void
    {
        self::assertSame('spotify premium', ServiceSimilarity::normalize('  Spotify  Premium!  '));
        self::assertSame('acme cloud plan pro', ServiceSimilarity::normalize('Acme Cloud — Plan Pro'));
    }
}
