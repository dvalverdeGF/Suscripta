<?php

declare(strict_types=1);

namespace App\Documents\Domain\Enum;

/**
 * De dónde salió el documento (ARCHITECTURE.md §4.4).
 *
 * Importa para la confianza y para el borrado: un documento que llegó de un
 * correo desaparece cuando se desconecta el buzón, y uno subido a mano no.
 */
enum DocumentSource: string
{
    case EMAIL_ATTACHMENT = 'email_attachment';
    case EMAIL_BODY = 'email_body';
    case MANUAL_UPLOAD = 'manual_upload';

    public function label(): string
    {
        return match ($this) {
            self::EMAIL_ATTACHMENT => 'Adjunto de correo',
            self::EMAIL_BODY => 'Cuerpo del correo',
            self::MANUAL_UPLOAD => 'Subido a mano',
        };
    }

    /**
     * Los documentos que dependen de un buzón conectado. Al desconectarlo hay
     * que eliminarlos: el usuario retiró el permiso de acceso a ese correo.
     */
    public function dependsOnMailbox(): bool
    {
        return self::MANUAL_UPLOAD !== $this;
    }
}
