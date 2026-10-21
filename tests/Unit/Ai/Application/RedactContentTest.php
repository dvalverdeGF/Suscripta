<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Application;

use App\Ai\Application\RedactContent;
use App\Ai\Domain\ValueObject\ExtractionContext;

use const PHP_EOL;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * El redactado es **obligatorio** antes de llamar a un modelo (ARCHITECTURE.md
 * §13.8), así que estas pruebas fijan dos cosas:
 *
 * - lo que **se quita** (ruido, firmas, avisos legales, hilo citado), que es
 *   lo que encarece la llamada y distrae al modelo;
 * - lo que **no se quita** (el cuerpo y la tabla de importes), que es
 *   justamente lo que se quiere extraer.
 */
#[CoversClass(RedactContent::class)]
final class RedactContentTest extends TestCase
{
    private RedactContent $redactor;

    protected function setUp(): void
    {
        $this->redactor = new RedactContent();
    }

    public function testItStripsHtmlTagsButKeepsTheText(): void
    {
        $text = $this->redact('<p>Factura <strong>Netflix</strong> por 12,99 €</p>');

        self::assertStringContainsString('Netflix', $text);
        self::assertStringContainsString('12,99 €', $text);
        self::assertStringNotContainsString('<p>', $text);
        self::assertStringNotContainsString('<strong>', $text);
    }

    public function testItRemovesScriptsAndStyles(): void
    {
        $text = $this->redact('<style>body{color:red}</style><script>alert(1)</script><p>Importe 9,99 €</p>');

        self::assertStringNotContainsString('alert(1)', $text);
        self::assertStringNotContainsString('color:red', $text);
        self::assertStringContainsString('9,99 €', $text);
    }

    public function testItRemovesTheQuotedThread(): void
    {
        $text = $this->redact("Importe 12,99 €\n> El mes pasado eran 11,99 €\n> Att:--\nGracias");

        self::assertStringNotContainsString('11,99 €', $text);
        self::assertStringContainsString('12,99 €', $text);
    }

    public function testItRemovesTheLegalNotice(): void
    {
        $text = $this->redact("Importe 12,99 €\nAviso legal: este mensaje y sus archivos adjuntos contienen información confidencial.\nGracias");

        self::assertStringNotContainsString('información confidencial', $text);
        self::assertStringContainsString('12,99 €', $text);
    }

    public function testItRemovesTheSignature(): void
    {
        $text = $this->redactor->stripNoise('Importe 12,99 €'.PHP_EOL.'Enviado desde mi iPhone.');

        self::assertStringNotContainsString('iPhone', $text);
        self::assertStringContainsString('12,99 €', $text);
    }

    public function testItRemovesTheUnsubscribeFooter(): void
    {
        $text = $this->redactor->stripNoise('Importe 12,99 €'.PHP_EOL.'Si no deseas recibir más correos, date de baja.');

        self::assertStringNotContainsString('date de baja', $text);
        self::assertStringContainsString('12,99 €', $text);
    }

    public function testItKeepsTheAmountTable(): void
    {
        $html = '<table><tr><td>Plan Pro</td><td>12,99 €</td></tr><tr><td>IVA</td><td>2,59 €</td></tr></table>';

        $text = $this->redactor->stripHtml($html);

        self::assertStringContainsString('Plan Pro', $text);
        self::assertStringContainsString('12,99 €', $text);
        self::assertStringContainsString('2,59 €', $text);
    }

    public function testItTruncatesKeepingTheBeginningOfTheText(): void
    {
        $redactor = new RedactContent(50);
        $text = $redactor->redact(new ExtractionContext(text: str_repeat('a', 200)))->text;

        self::assertLessThan(200, mb_strlen($text));
        self::assertStringContainsString('texto recortado', $text);
    }

    public function testItKeepsTheDeterministicDataAndMetadataUntouched(): void
    {
        $context = new ExtractionContext(
            text: 'Importe 12,99 €',
            deterministic: ['amountMinor' => 1299],
            metadata: ['subject' => 'Tu factura'],
        );

        $redacted = $this->redactor->redact($context);

        self::assertSame(1299, $redacted->deterministic['amountMinor']);
        self::assertSame('Tu factura', $redacted->metadata['subject']);
    }

    private function redact(string $text): string
    {
        return $this->redactor->redact(new ExtractionContext(text: $text))->text;
    }
}
