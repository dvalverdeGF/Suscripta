<?php

declare(strict_types=1);

namespace App\Processing\Application\Extraction;

use function checkdate;

use DateTimeImmutable;

use function mb_strtolower;
use function mb_substr;
use function preg_match_all;

use const PREG_SET_ORDER;

use function round;
use function sprintf;

use Throwable;

use function trim;

/**
 * Extrae fechas de un texto (ARCHITECTURE.md §13.6).
 *
 * Se limita a los formatos que aparecen de verdad en facturas europeas y
 * anglosajonas. Cualquier fecha que no encaje se descarta en lugar de
 * adivinarse: una fecha de renovación inventada genera un aviso falso, y un
 * aviso falso destruye la confianza en el producto más rápido que un aviso
 * ausente.
 */
final readonly class DateParser
{
    private const string ISO_PATTERN = '/\b(?<y>\d{4})-(?<m>\d{2})-(?<d>\d{2})\b/';

    /** Formato europeo: `03/10/2026`, `3.10.26`, `03-10-2026`. */
    private const string EURO_PATTERN = '/\b(?<d>\d{1,2})[\/.\-](?<m>\d{1,2})[\/.\-](?<y>\d{2,4})\b/';

    private const string SPANISH_PATTERN = '/\b(?<d>\d{1,2})\s+de\s+(?<month>[a-záéíóúñ]+)\s+de\s+(?<y>\d{4})\b/iu';

    private const string ENGLISH_PATTERN = '/\b(?<month>[a-z]+)\s+(?<d>\d{1,2})(?:st|nd|rd|th)?,?\s+(?<y>\d{4})\b/i';

    /** @var array<string, int> */
    private const array MONTHS = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
        'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
        'noviembre' => 11, 'diciembre' => 12,
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6,
        'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8,
        'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * Todas las fechas plausibles del texto, en orden de aparición.
     *
     * @return list<DateTimeImmutable>
     */
    public function findAll(string $text, ?DateTimeImmutable $notBefore = null): array
    {
        if ('' === trim($text)) {
            return [];
        }

        $found = [];

        foreach ([self::ISO_PATTERN, self::EURO_PATTERN, self::SPANISH_PATTERN, self::ENGLISH_PATTERN] as $pattern) {
            if (false === preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                $date = $this->build($match);

                if (null === $date) {
                    continue;
                }

                if (null !== $notBefore && $date < $notBefore) {
                    continue;
                }

                $found[] = $date;
            }
        }

        return $found;
    }

    public function findFirst(string $text, ?DateTimeImmutable $notBefore = null): ?DateTimeImmutable
    {
        $dates = $this->findAll($text, $notBefore);

        return $dates[0] ?? null;
    }

    /**
     * @param array<string, string> $match
     */
    /**
     * @param array<int|string, string> $match
     */
    private function build(array $match): ?DateTimeImmutable
    {
        $year = (int) ($match['y'] ?? 0);
        $month = (int) ($match['m'] ?? 0);
        $day = (int) ($match['d'] ?? 0);

        if (isset($match['month']) && '' !== $match['month']) {
            $month = $this->monthNumber($match['month']) ?? 0;
        }

        // Un año de dos dígitos en una factura es siempre 20xx.
        if ($year > 0 && $year < 100) {
            $year += 2000;
        }

        if ($year < 2000 || $year > 2100 || !checkdate($month, $day, $year)) {
            return null;
        }

        try {
            return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
        } catch (Throwable) {
            return null;
        }
    }

    private function monthNumber(string $name): ?int
    {
        $normalized = mb_strtolower(trim($name));

        if (isset(self::MONTHS[$normalized])) {
            return self::MONTHS[$normalized];
        }

        // Abreviaturas de tres letras ("oct." → "oct").
        $short = mb_substr($normalized, 0, 3);

        return self::MONTHS[$short] ?? null;
    }

    /**
     * Distancia en días entre dos fechas, redondeada. Se usa para deducir la
     * periodicidad a partir de dos cobros consecutivos.
     */
    public function daysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $seconds = $to->getTimestamp() - $from->getTimestamp();

        return (int) round($seconds / 86400);
    }
}
