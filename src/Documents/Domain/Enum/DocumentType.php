<?php

declare(strict_types=1);

namespace App\Documents\Domain\Enum;

use App\Mailbox\Domain\Enum\MessageClassification;

/**
 * Naturaleza de un documento conservado (ARCHITECTURE.md §4.4).
 *
 * Es deliberadamente más pobre que `MessageClassification`: un correo puede
 * ser un "aviso de renovación" (clasificación del pipeline) sin que el
 * documento adjunto deje de ser una factura. La clasificación describe el
 * mensaje; el tipo describe el documento.
 */
enum DocumentType: string
{
    case INVOICE = 'invoice';
    case RECEIPT = 'receipt';
    case CONTRACT = 'contract';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::INVOICE => 'Factura',
            self::RECEIPT => 'Recibo',
            self::CONTRACT => 'Contrato',
            self::OTHER => 'Otro',
        };
    }

    /**
     * Traduce la clasificación del mensaje al tipo de documento.
     *
     * `UNKNOWN` cae en `OTHER` a propósito: no se inventa un tipo de documento
     * cuando no hay confianza suficiente.
     */
    public static function fromClassification(MessageClassification $classification): self
    {
        return match ($classification) {
            MessageClassification::INVOICE => self::INVOICE,
            MessageClassification::RECEIPT, MessageClassification::PAYMENT_CONFIRMATION => self::RECEIPT,
            default => self::OTHER,
        };
    }
}
