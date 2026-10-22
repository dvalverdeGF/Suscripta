<?php

declare(strict_types=1);

namespace App\Tests\Unit\Processing\Application\Knowledge;

use App\Processing\Application\Knowledge\PatternTemplate;

use function mb_strlen;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function str_repeat;

/**
 * La plantilla de un texto variable (ARCHITECTURE.md §13.12).
 *
 * La propiedad que importa no es el resultado exacto de la expresión regular
 * sino que **dos textos que solo se diferencian en una fecha y un número
 * producen la misma plantilla**. Si no, el patrón no sirve para reconocer el
 * siguiente correo del mismo proveedor, que es justo para lo que existe.
 */
#[CoversClass(PatternTemplate::class)]
final class PatternTemplateTest extends TestCase
{
    public function testTwoInvoicesOfTheSameProviderShareTheTemplate(): void
    {
        $january = PatternTemplate::build('Factura 2026-01-15 - 45,90 €');
        $february = PatternTemplate::build('Factura 2026-02-15 - 45,90 €');

        self::assertNotNull($january);
        self::assertNotNull($february);
        self::assertSame($january, $february);
    }

    public function testTheTemplateMatchesTheNextInvoice(): void
    {
        $pattern = PatternTemplate::build('Factura 2026-01-15 - 45,90 €');

        self::assertNotNull($pattern);
        self::assertSame(1, preg_match($pattern, 'Factura 2026-03-11 - 51,20 €'));
        self::assertSame(1, preg_match($pattern, 'Factura 2027-12-01 - 9,99 €'));
    }

    public function testTheTemplateDoesNotMatchADifferentText(): void
    {
        $pattern = PatternTemplate::build('Factura 2026-01-15 - 45,90 €');

        self::assertNotNull($pattern);
        self::assertSame(0, preg_match($pattern, 'Alquiler del local'));
    }

    public function testAnEmptyTextHasNoTemplate(): void
    {
        self::assertNull(PatternTemplate::build('   '));
    }

    public function testAVeryLongTextHasNoTemplate(): void
    {
        self::assertNull(PatternTemplate::build(str_repeat('a', PatternTemplate::MAX_SOURCE_LENGTH + 1)));
    }

    public function testTheResultIsAPatternAnchoredToTheWholeText(): void
    {
        $pattern = PatternTemplate::build('Factura 2026-01-15 - 45,90 €');

        self::assertNotNull($pattern);
        self::assertStringStartsWith('/^', $pattern);
        self::assertStringEndsWith('$/iu', $pattern);
    }

    #[DataProvider('documentNames')]
    public function testAttachmentNamesAlsoProduceAStableTemplate(string $first, string $second): void
    {
        $one = PatternTemplate::build($first);
        $two = PatternTemplate::build($second);

        self::assertNotNull($one);
        self::assertNotNull($two);
        self::assertSame($one, $two);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function documentNames(): iterable
    {
        yield 'invoice with a number' => ['Factura-2026-01-15.pdf', 'Factura-2026-02-15.pdf'];
        yield 'invoice with a reference' => ['factura_ABC123.pdf', 'factura_XYZ789.pdf'];
    }

    public function testADateInSlashFormatIsAlsoRecognized(): void
    {
        $pattern = PatternTemplate::build('Factura 15/01/2026 - 45,90 €');

        self::assertNotNull($pattern);
        self::assertSame(1, preg_match($pattern, 'Factura 01/03/2026 - 45,90 €'));
    }

    public function testTheTemplateFitsInTheColumn(): void
    {
        $pattern = PatternTemplate::build('Factura 2026-01-15 - 45,90 €');

        self::assertNotNull($pattern);
        self::assertLessThanOrEqual(PatternTemplate::MAX_PATTERN_LENGTH, mb_strlen($pattern));
    }
}
