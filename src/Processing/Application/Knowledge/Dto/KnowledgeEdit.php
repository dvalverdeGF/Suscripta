<?php

declare(strict_types=1);

namespace App\Processing\Application\Knowledge\Dto;

use App\Documents\Domain\Enum\DocumentType;
use App\Shared\Domain\ValueObject\BillingPeriod;
use Symfony\Component\Uid\Uuid;

/**
 * Lo que el usuario quiere que diga una entrada del conocimiento.
 *
 * Todo es editable: el valor, el proveedor, el tipo de documento, la
 * periodicidad y la confianza. El conocimiento aprendido es una ayuda de trabajo
 * del usuario, no un dato que el sistema tiene derecho a congelar.
 */
final readonly class KnowledgeEdit
{
    public function __construct(
        public string $value,
        public ?Uuid $providerId = null,
        public ?DocumentType $documentType = null,
        public ?BillingPeriod $billingPeriod = null,
        public int $confidence = 100,
    ) {
    }
}
