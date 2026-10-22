<?php

declare(strict_types=1);

namespace App\Tests\Unit\Privacy;

use App\Privacy\Application\Dto\DataExport;
use DateTimeImmutable;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * El formato de la exportación: JSON para el archivo, CSV para la hoja de cálculo.
 *
 * El JSON es el contrato portable: otro programa tiene que poder leerlo sin
 * saber nada de Suscripta. El CSV es la comodidad de abrirlo con una hoja de
 * cálculo, y por eso sus columnas son la unión de las de todas las filas.
 */
#[CoversClass(DataExport::class)]
final class DataExportTest extends TestCase
{
    public function testTheJsonHasTheGeneratedAtAndTheSections(): void
    {
        $export = $this->export([
            'services' => ['meta' => ['count' => 1], 'rows' => [['name' => 'Netflix']]],
        ]);

        $decoded = json_decode($export->toJson(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertSame('2026-10-05T10:00:00+00:00', $decoded['generatedAt']);
        self::assertArrayHasKey('services', $decoded['sections']);
        self::assertSame('Netflix', $decoded['sections']['services']['rows'][0]['name']);
    }

    public function testTheSectionNamesAreTheKeysOfTheSections(): void
    {
        $export = $this->export([
            'services' => [],
            'documents' => [],
        ]);

        self::assertSame(['documents', 'services'], $export->sectionNames());
    }

    public function testOnlyTheRowsCount(): void
    {
        $export = $this->export([
            'services' => ['meta' => ['count' => 99], 'rows' => [['name' => 'a'], ['name' => 'b']]],
            'identity' => ['rows' => [['email' => 'ada@example.com']]],
        ]);

        self::assertSame(3, $export->rowCount());
    }

    public function testASectionWithoutRowsCountsZero(): void
    {
        $export = $this->export(['identity' => ['meta' => ['name' => 'Ada']]]);

        self::assertSame(0, $export->rowCount());
    }

    public function testTheCsvHasAHeaderAndOneLinePerRow(): void
    {
        $export = $this->export([
            'services' => ['rows' => [['name' => 'Netflix', 'amount' => 990], ['name' => 'Spotify', 'amount' => 1290]]],
        ]);

        $csv = $export->toCsv('services');
        $lines = explode("\n", trim($csv));

        self::assertSame('name,amount', $lines[0]);
        self::assertSame('Netflix,990', $lines[1]);
        self::assertSame('Spotify,1290', $lines[2]);
    }

    public function testTheColumnsAreTheUnionOfAllTheRows(): void
    {
        $export = $this->export([
            'services' => ['rows' => [['name' => 'Netflix'], ['name' => 'Spotify', 'amount' => 1290]]],
        ]);

        $lines = explode("\n", trim($export->toCsv('services')));

        self::assertSame('name,amount', $lines[0]);
        self::assertSame('Netflix,', $lines[1]);
        self::assertSame('Spotify,1290', $lines[2]);
    }

    public function testTheCsvOfAnUnknownSectionIsEmpty(): void
    {
        $export = $this->export(['services' => ['rows' => [['name' => 'Netflix']]]]);

        self::assertSame('', $export->toCsv('no_existe'));
    }

    public function testTheCsvOfAnEmptySectionIsEmpty(): void
    {
        $export = $this->export(['services' => ['rows' => []]]);

        self::assertSame('', $export->toCsv('services'));
    }

    /**
     * @param array<string, array<string, mixed>> $sections
     */
    private function export(array $sections): DataExport
    {
        return new DataExport($sections, new DateTimeImmutable('2026-10-05 10:00:00'));
    }
}
