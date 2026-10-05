<?php

declare(strict_types=1);

namespace App\Mailbox\UI\Form;

use function implode;
use function preg_split;
use function trim;

/**
 * Datos del formulario de ingesta por reenvío.
 *
 * Los remitentes se editan como texto libre, uno por línea, porque es lo que
 * un autónomo espera poder pegar desde su libreta de direcciones. La
 * normalización (minúsculas, deduplicación, validación) la hace la entidad.
 */
final class ForwardingFormData
{
    public ?string $senders = null;

    /**
     * @param list<string> $senders
     */
    public static function fromSenders(array $senders): self
    {
        $data = new self();
        $data->senders = implode("\n", $senders);

        return $data;
    }

    /**
     * @return list<string>
     */
    public function toSenders(): array
    {
        $lines = preg_split('/[\r\n,;]+/', $this->senders ?? '') ?: [];

        $senders = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ('' !== $line) {
                $senders[] = $line;
            }
        }

        return $senders;
    }
}
