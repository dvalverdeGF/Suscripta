<?php

declare(strict_types=1);

namespace App\Ai\Domain\ValueObject;

use App\Mailbox\Domain\Enum\ExtractionTier;

use function array_filter;
use function is_int;
use function is_string;

/**
 * Contexto que se manda al modelo de IA para extraer los datos de un correo.
 *
 * ## Qué lleva y qué no
 *
 * Lleva **el texto ya redactado** y **los datos ya obtenidos de forma
 * determinista**. Los datos deterministas se mandan como contexto, no como
 * resultado: el modelo puede corregirlos (un importe mal parseado, una fecha
 * en formato raro) pero la validación final decide qué se guarda. Así el modelo
 * no tiene que adivinar lo que el código ya sabe.
 *
 * ## Por qué el redactado va antes
 *
 * Un correo de suscripción lleva firmas, avisos legales, pies de página,
 * enlaces de seguimiento y el hilo citado de respuestas anteriores. Mandarlo
 * crudo:
 *
 * - encarece la llamada (más tokens de los necesarios),
 * - hace que el modelo se distraiga con el ruido,
 * - y puede filtrar datos personales ajenos a la suscripción (la firma del
 *   empleado, el teléfono de atención al cliente, los destinatarios de «CC»).
 *
 * Por eso el redactado es **obligatorio** antes de la llamada, no una
 * optimización opcional.
 */
final readonly class ExtractionContext
{
    /**
     * @param array<string, mixed>  $deterministic datos ya extraídos sin IA
     * @param array<string, string> $metadata      cabeceras útiles (remitente, asunto, fecha)
     */
    public function __construct(
        public string $text,
        public array $deterministic = [],
        public array $metadata = [],
        public ExtractionTier $currentTier = ExtractionTier::DETERMINISTIC,
    ) {
    }

    /**
     * ¿Se ha extraído ya algo por la vía determinista?
     *
     * Si no, la IA parte de cero; si sí, la IA solo tiene que mejorar lo que
     * falta.
     */
    public function hasDeterministicData(): bool
    {
        return [] !== array_filter(
            $this->deterministic,
            static fn (mixed $value): bool => null !== $value && '' !== $value,
        );
    }

    /**
     * Importe ya detectado, si lo hay.
     */
    public function knownAmountMinor(): ?int
    {
        $amount = $this->deterministic['amountMinor'] ?? null;

        return is_int($amount) ? $amount : null;
    }

    /**
     * Proveedor ya detectado, si lo hay.
     */
    public function knownProvider(): ?string
    {
        $provider = $this->deterministic['providerName'] ?? null;

        return is_string($provider) ? $provider : null;
    }

    /**
     * Periodicidad ya detectada, si la hay.
     */
    public function knownPeriod(): ?string
    {
        $period = $this->deterministic['billingPeriod'] ?? null;

        return is_string($period) && '' !== $period ? $period : null;
    }

    /**
     * ¿Está el contexto ya completo?
     *
     * «Completo» es lo mismo que «accionable» en el resto del sistema: hay
     * importe, hay proveedor y hay una periodicidad recurrente. Con eso ya se
     * puede calcular un coste, y llamar a un modelo sería gastar dinero para
     * no obtener nada.
     */
    public function isComplete(): bool
    {
        return null !== $this->knownAmountMinor()
            && null !== $this->knownProvider()
            && null !== $this->knownPeriod();
    }

    /**
     * Resumen del contexto, para el prompt.
     */
    public function summary(): string
    {
        $parts = [];

        foreach ($this->metadata as $key => $value) {
            if (is_string($value) && '' !== $value) {
                $parts[] = $key.': '.$value;
            }
        }

        return implode("\n", $parts);
    }
}
