<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Application;

use App\Ai\Application\AiPriceCatalog;
use App\Ai\Domain\ValueObject\AiPrice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Los precios del catálogo son **orientativos** y se guardan por proveedor.
 *
 * La propiedad que importa aquí no es el número: es que un proveedor
 * desconocido vale cero (un cero conocido) y que un ajuste del operador manda
 * sobre el catálogo, porque los precios cambian y las condiciones se
 * negocian.
 */
#[CoversClass(AiPriceCatalog::class)]
#[CoversClass(AiPrice::class)]
final class AiPriceCatalogTest extends TestCase
{
    public function testAnUnknownProviderCostsNothingRatherThanGuessing(): void
    {
        $catalog = new AiPriceCatalog();

        self::assertSame(0, $catalog->for('proveedor-inexistente')->costMicro(1_000_000, 1_000_000));
    }

    public function testTheDefaultPriceIsUsedWhenThereIsNoOverride(): void
    {
        $catalog = new AiPriceCatalog();
        $price = $catalog->forProvider('groq', []);

        self::assertSame(0.59, $price->promptPerMillion);
        self::assertSame(0.79, $price->completionPerMillion);
    }

    public function testTheOperatorOverrideWinsOverTheCatalog(): void
    {
        $catalog = new AiPriceCatalog();
        $price = $catalog->forProvider('groq', [
            'prompt_price' => '1.50',
            'completion_price' => '3.00',
        ]);

        self::assertSame(1.50, $price->promptPerMillion);
        self::assertSame(3.00, $price->completionPerMillion);
    }

    public function testANonNumericOverrideIsIgnoredInsteadOfBreakingTheCall(): void
    {
        $catalog = new AiPriceCatalog();
        $price = $catalog->forProvider('groq', ['prompt_price' => 'mucho']);

        self::assertSame(0.59, $price->promptPerMillion);
    }

    public function testALocalProviderIsFree(): void
    {
        $catalog = new AiPriceCatalog();

        self::assertSame(0, $catalog->for('ollama')->costMicro(1_000_000, 1_000_000));
    }

    public function testTheCostIsComputedInMicrocentsAtTheMomentOfTheCall(): void
    {
        $price = new AiPrice(promptPerMillion: 0.59, completionPerMillion: 0.79);

        // 1.000 tokens de entrada + 1.000 de salida ≈ 0,00138 € → 1.380
        // microcéntimos. En céntimos sería 0, y el gasto se perdería (D-68).
        self::assertSame(1_380, $price->costMicro(1_000, 1_000));

        // 1.000.000 de entrada + 1.000.000 de salida = 1,38 € → 1.380.000
        // microcéntimos (138 céntimos).
        self::assertSame(1_380_000, $price->costMicro(1_000_000, 1_000_000));
    }

    public function testACheapCallKeepsItsCostInsteadOfRoundingItAway(): void
    {
        $price = new AiPrice(promptPerMillion: 0.59, completionPerMillion: 0.79);

        // Una llamada pequeña vale unas centésimas de céntimo: redondeada a
        // céntimos sería 0 y el panel enseñaría un gasto inexistente.
        $micro = $price->costMicro(200, 50);

        self::assertGreaterThan(0, $micro);
        self::assertSame(0, (int) round($micro / 10_000));
    }
}
