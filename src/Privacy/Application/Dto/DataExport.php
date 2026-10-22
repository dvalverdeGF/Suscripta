<?php

declare(strict_types=1);

namespace App\Privacy\Application\Dto;

use function array_key_exists;
use function array_keys;
use function array_unique;
use function count;

use DateTimeImmutable;
use DateTimeInterface;

use function fclose;
use function fopen;
use function fputcsv;
use function implode;
use function is_array;
use function is_bool;
use function is_object;
use function is_scalar;
use function json_encode;

use const JSON_PARTIAL_OUTPUT_ON_ERROR;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

use JsonSerializable;

use function ksort;
use function method_exists;
use function rewind;
use function stream_get_contents;

/**
 * Exportación de los datos de una organización.
 *
 * Es un documento **portable**: lo que el usuario se lleva cuando ejercita su
 * derecho de acceso o de portabilidad. Por eso vive en memoria como una
 * estructura simple (secciones → filas) y no como entidades: un JSON con
 * llaves estables es lo que otro programa puede leer.
 */
final readonly class DataExport
{
    /**
     * @var array<string, array<string, mixed>>
     */
    public array $sections;

    /**
     * @param array<string, array<string, mixed>> $sections
     */
    public function __construct(
        array $sections,
        public DateTimeImmutable $generatedAt,
    ) {
        // El documento es canónico: las secciones van siempre ordenadas, diga
        // lo que diga el orden en que se registraron los módulos.
        ksort($sections);

        $this->sections = $sections;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generatedAt' => $this->generatedAt->format('c'),
            'sections' => $this->sections,
        ];
    }

    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return list<string>
     */
    public function sectionNames(): array
    {
        return array_keys($this->sections);
    }

    /**
     * Número total de filas exportadas, en todas las secciones.
     */
    public function rowCount(): int
    {
        $total = 0;

        foreach ($this->sections as $section) {
            $total += count($this->rowsOf($section));
        }

        return $total;
    }

    /**
     * Exporta una sección en CSV.
     *
     * El CSV es para los datos que el usuario quiere abrir en una hoja de
     * cálculo; el resto de secciones viajan en JSON. Las columnas son la
     * unión de las claves de todas las filas, para que ninguna se pierda.
     */
    public function toCsv(string $section): string
    {
        $rows = $this->rowsOf($this->sections[$section] ?? []);

        if ([] === $rows) {
            return '';
        }

        $columns = [];

        foreach ($rows as $row) {
            foreach (array_keys($row) as $column) {
                $columns[] = $column;
            }
        }

        $columns = array_values(array_unique($columns));

        $handle = fopen('php://temp', 'r+');

        if (false === $handle) {
            return '';
        }

        fputcsv($handle, $columns, escape: '');

        foreach ($rows as $row) {
            $line = [];

            foreach ($columns as $column) {
                $line[] = $this->stringify($row[$column] ?? null);
            }

            fputcsv($handle, $line, escape: '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return false === $csv ? '' : $csv;
    }

    /**
     * Convierte un valor de una fila en texto para la celda del CSV.
     *
     * Una celda del CSV es texto, pero los datos exportados no siempre lo
     * son: hay listas (los precios de un servicio) y estructuras (el histórico
     * de eventos). Una lista de valores simples se aplana con comas, que es
     * lo que se lee bien en una hoja de cálculo; una estructura se serializa
     * en JSON, que es lo único que no pierde información.
     */
    private function stringify(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('c');
        }

        if (is_array($value)) {
            $simple = true;

            foreach ($value as $item) {
                if (!is_scalar($item) && !$item instanceof DateTimeInterface) {
                    $simple = false;
                    break;
                }
            }

            if ($simple) {
                $parts = [];

                foreach ($value as $item) {
                    $parts[] = $this->stringify($item);
                }

                return implode(', ', $parts);
            }

            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
        }

        if ($value instanceof JsonSerializable) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $section
     *
     * @return list<array<string, mixed>>
     */
    private function rowsOf(array $section): array
    {
        if (!array_key_exists('rows', $section) || !is_array($section['rows'])) {
            return [];
        }

        $rows = [];

        foreach ($section['rows'] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
