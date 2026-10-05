<?php

declare(strict_types=1);

namespace App\Processing\Domain\Dto;

/**
 * Una señal concreta que ha contribuido al `billingScore` (ARCHITECTURE.md
 * §13.4).
 *
 * Se guarda el peso aplicado y un detalle legible porque el usuario tiene
 * derecho a saber por qué se ha analizado —o descartado— un correo suyo. Un
 * score sin desglose es indistinguible de una caja negra.
 */
final readonly class BillingSignal
{
    public function __construct(
        public string $signal,
        public int $weight,
        public ?string $detail = null,
    ) {
    }

    /**
     * @return array{signal: string, weight: int, detail?: string}
     */
    public function toArray(): array
    {
        $data = ['signal' => $this->signal, 'weight' => $this->weight];

        if (null !== $this->detail) {
            $data['detail'] = $this->detail;
        }

        return $data;
    }
}
