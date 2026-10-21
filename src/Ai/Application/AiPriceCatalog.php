<?php

declare(strict_types=1);

namespace App\Ai\Application;

use App\Ai\Domain\ValueObject\AiPrice;

use function is_numeric;

/**
 * Precios por defecto de los proveedores de IA.
 *
 * ## Para qué sirve esto
 *
 * Sin precios, `AiUsage` solo podría guardar tokens, y el panel de coste
 * tendría que saber multiplicar por su cuenta. Con precios, el coste queda
 * **persistido en el momento de la llamada** (D-36) y el panel solo suma.
 *
 * ## De dónde salen los números
 *
 * Son las tarifas públicas de cada proveedor en euros por millón de tokens.
 * Se guardan en la integración (`prompt_price`, `completion_price`) para que
 * el operador pueda corregirlos: los precios cambian, y un cliente con
 * condiciones negociadas necesita poder reflejarlas sin esperar a un
 * despliegue.
 *
 * ## Los valores por defecto son orientativos
 *
 * Si un proveedor no aparece en el catálogo, el coste se registra como cero.
 * Es preferible un cero conocido a un número inventado: el panel dirá «0,00 €»
 * en vez de mentir con una cifra.
 */
final readonly class AiPriceCatalog
{
    /**
     * @var array<string, AiPrice>
     */
    private array $prices;

    /**
     * @param array<string, AiPrice> $prices
     */
    public function __construct(array $prices = [])
    {
        $this->prices = $prices + self::defaults();
    }

    /**
     * Precio por defecto de un proveedor.
     */
    public function for(string $providerKey): AiPrice
    {
        return $this->prices[$providerKey] ?? AiPrice::free();
    }

    /**
     * Precios por defecto conocidos.
     *
     * @return array<string, AiPrice>
     */
    public static function defaults(): array
    {
        return [
            // Groq: modelos abiertos, precio muy bajo.
            'groq' => new AiPrice(promptPerMillion: 0.59, completionPerMillion: 0.79),
            // OpenAI: gama media y alta.
            'openai' => new AiPrice(promptPerMillion: 0.15, completionPerMillion: 0.60),
            // Anthropic: gama alta.
            'anthropic' => new AiPrice(promptPerMillion: 0.80, completionPerMillion: 4.00),
            // Ollama: autoalojado, no se cobra por token.
            'ollama' => AiPrice::free(),
        ];
    }

    /**
     * Construye el precio de un proveedor a partir de sus ajustes.
     *
     * Los ajustes tienen prioridad sobre el catálogo: son la corrección
     * explícita del operador. Un ajuste no numérico se ignora en vez de
     * romper la llamada.
     *
     * @param array<string, string> $settings
     */
    public function forProvider(string $providerKey, array $settings): AiPrice
    {
        $default = $this->for($providerKey);
        $prompt = $settings['prompt_price'] ?? null;
        $completion = $settings['completion_price'] ?? null;

        return new AiPrice(
            promptPerMillion: is_numeric($prompt) ? (float) $prompt : $default->promptPerMillion,
            completionPerMillion: is_numeric($completion) ? (float) $completion : $default->completionPerMillion,
        );
    }
}
