<?php

declare(strict_types=1);

namespace App\Processing\Domain\Enum;

/**
 * Tipos de conocimiento que el sistema aprende de un buzón concreto
 * (ARCHITECTURE.md §13.12).
 *
 * Cada tipo responde a una pregunta distinta y se guarda en filas separadas
 * porque se originan en fuentes distintas y se poda de forma distinta:
 *
 * - `SENDER_MAPPING`: *este remitente es este proveedor*.
 * - `PROVIDER_HINT`: *este dominio o fragmento de asunto apunta a este
 *   proveedor* (menos preciso que el remitente).
 * - `SUBJECT_PATTERN`: *los asuntos con esta plantilla son de este tipo de
 *   documento*.
 * - `DOCUMENT_PATTERN`: *los adjuntos con esta plantilla son de este tipo de
 *   documento*.
 * - `IGNORED_SENDER`: *de este remitente no quiero saber nada*.
 */
enum MailboxKnowledgeType: string
{
    case SENDER_MAPPING = 'sender_mapping';
    case PROVIDER_HINT = 'provider_hint';
    case SUBJECT_PATTERN = 'subject_pattern';
    case DOCUMENT_PATTERN = 'document_pattern';
    case IGNORED_SENDER = 'ignored_sender';

    public function label(): string
    {
        return match ($this) {
            self::SENDER_MAPPING => 'Remitente → proveedor',
            self::PROVIDER_HINT => 'Pista de proveedor',
            self::SUBJECT_PATTERN => 'Plantilla de asunto',
            self::DOCUMENT_PATTERN => 'Plantilla de adjunto',
            self::IGNORED_SENDER => 'Remitente ignorado',
        };
    }

    /**
     * Los patrones se guardan como expresiones regulares y no se normalizan a
     * minúsculas: distinguir mayúsculas importa en un patrón.
     */
    public function isPattern(): bool
    {
        return match ($this) {
            self::SUBJECT_PATTERN, self::DOCUMENT_PATTERN => true,
            default => false,
        };
    }

    /**
     * Tipos que apuntan a un proveedor del catálogo.
     */
    public function carriesProvider(): bool
    {
        return match ($this) {
            self::SENDER_MAPPING, self::PROVIDER_HINT => true,
            default => false,
        };
    }

    /**
     * Tipos que describen la naturaleza del documento.
     */
    public function carriesDocumentType(): bool
    {
        return match ($this) {
            self::SUBJECT_PATTERN, self::DOCUMENT_PATTERN => true,
            default => false,
        };
    }

    /**
     * Tipos que sirven para saltar el mensaje entero sin pagar IA.
     */
    public function isExclusion(): bool
    {
        return self::IGNORED_SENDER === $this;
    }
}
