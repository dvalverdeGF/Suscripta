<?php

declare(strict_types=1);

namespace App\Shared\Domain\Enum;

use function in_array;

/**
 * Acciones que quedan registradas en el libro de auditoría.
 *
 * Solo se auditan acciones sensibles: accesos a datos de terceros, cambios de
 * credenciales, conexión de buzones y operaciones destructivas. Auditar todo
 * convertiría el registro en ruido (SECURITY.md §7).
 */
enum AuditAction: string
{
    // Identidad
    case USER_REGISTERED = 'user.registered';
    case USER_LOGGED_IN = 'user.logged_in';
    case USER_LOGGED_OUT = 'user.logged_out';
    case USER_LOGIN_FAILED = 'user.login_failed';
    case USER_PASSWORD_CHANGED = 'user.password_changed';
    case USER_PASSWORD_RESET_REQUESTED = 'user.password_reset_requested';
    case USER_EMAIL_VERIFIED = 'user.email_verified';
    case USER_EMAIL_VERIFICATION_SENT = 'user.email_verification_sent';
    case ORGANIZATION_SWITCHED = 'organization.switched';
    case ORGANIZATION_CREATED = 'organization.created';
    case ORGANIZATION_UPDATED = 'organization.updated';

    // Buzones de correo
    case EMAIL_ACCOUNT_CONNECTED = 'email_account.connected';
    case EMAIL_ACCOUNT_CONNECTION_FAILED = 'email_account.connection_failed';
    case EMAIL_ACCOUNT_DISCONNECTED = 'email_account.disconnected';
    case EMAIL_ACCOUNT_DELETED = 'email_account.deleted';
    case EMAIL_SYNC_STARTED = 'email_sync.started';
    case EMAIL_SYNC_FINISHED = 'email_sync.finished';

    // Documentos y datos derivados
    case DOCUMENT_VIEWED = 'document.viewed';
    case DOCUMENT_DOWNLOADED = 'document.downloaded';
    case DOCUMENT_DELETED = 'document.deleted';
    case DATA_EXPORTED = 'data.exported';
    case DATA_PURGED = 'data.purged';

    // Dominio
    case SERVICE_CREATED = 'service.created';
    case SERVICE_UPDATED = 'service.updated';
    case SERVICE_DELETED = 'service.deleted';
    case SERVICE_PRICE_CHANGED = 'service.price_changed';
    case DISCOVERY_CONFIRMED = 'discovery.confirmed';
    case DISCOVERY_DISMISSED = 'discovery.dismissed';
    case DISCOVERY_CREATED = 'discovery.created';

    // Avisos y notificaciones
    case ALERT_GENERATED = 'alert.generated';
    case ALERT_ACKNOWLEDGED = 'alert.acknowledged';
    case ALERT_DISMISSED = 'alert.dismissed';
    case NOTIFICATION_SENT = 'notification.sent';
    case NOTIFICATION_FAILED = 'notification.failed';
    case NOTIFICATION_PREFERENCES_UPDATED = 'notification.preferences_updated';

    // Catálogo
    case CATALOG_SEEDED = 'catalog.seeded';

    // IA
    case AI_EXTRACTION_PERFORMED = 'ai.extraction_performed';
    case AI_BUDGET_EXCEEDED = 'ai.budget_exceeded';

    public function label(): string
    {
        return match ($this) {
            self::USER_REGISTERED => 'Alta de usuario',
            self::USER_LOGGED_IN => 'Inicio de sesión',
            self::USER_LOGGED_OUT => 'Cierre de sesión',
            self::USER_LOGIN_FAILED => 'Intento de acceso fallido',
            self::USER_PASSWORD_CHANGED => 'Cambio de contraseña',
            self::USER_PASSWORD_RESET_REQUESTED => 'Solicitud de restablecimiento de contraseña',
            self::USER_EMAIL_VERIFIED => 'Correo verificado',
            self::USER_EMAIL_VERIFICATION_SENT => 'Correo de verificación enviado',
            self::ORGANIZATION_SWITCHED => 'Cambio de organización activa',
            self::ORGANIZATION_CREATED => 'Organización creada',
            self::ORGANIZATION_UPDATED => 'Organización actualizada',
            self::EMAIL_ACCOUNT_CONNECTED => 'Cuenta de correo conectada',
            self::EMAIL_ACCOUNT_CONNECTION_FAILED => 'Fallo al conectar la cuenta de correo',
            self::EMAIL_ACCOUNT_DISCONNECTED => 'Cuenta de correo desconectada',
            self::EMAIL_ACCOUNT_DELETED => 'Cuenta de correo eliminada',
            self::EMAIL_SYNC_STARTED => 'Sincronización iniciada',
            self::EMAIL_SYNC_FINISHED => 'Sincronización finalizada',
            self::DOCUMENT_VIEWED => 'Documento consultado',
            self::DOCUMENT_DOWNLOADED => 'Documento descargado',
            self::DOCUMENT_DELETED => 'Documento eliminado',
            self::DATA_EXPORTED => 'Datos exportados',
            self::DATA_PURGED => 'Datos purgados',
            self::SERVICE_CREATED => 'Servicio creado',
            self::SERVICE_UPDATED => 'Servicio actualizado',
            self::SERVICE_DELETED => 'Servicio eliminado',
            self::SERVICE_PRICE_CHANGED => 'Precio del servicio modificado',
            self::DISCOVERY_CREATED => 'Descubrimiento creado',
            self::DISCOVERY_CONFIRMED => 'Descubrimiento confirmado',
            self::DISCOVERY_DISMISSED => 'Descubrimiento descartado',
            self::ALERT_GENERATED => 'Avisos generados',
            self::ALERT_ACKNOWLEDGED => 'Aviso marcado como visto',
            self::ALERT_DISMISSED => 'Aviso descartado',
            self::NOTIFICATION_SENT => 'Notificación enviada',
            self::NOTIFICATION_FAILED => 'Fallo al enviar la notificación',
            self::NOTIFICATION_PREFERENCES_UPDATED => 'Preferencias de avisos actualizadas',
            self::CATALOG_SEEDED => 'Catálogo global sembrado',
            self::AI_EXTRACTION_PERFORMED => 'Extracción con IA',
            self::AI_BUDGET_EXCEEDED => 'Presupuesto de IA agotado',
        };
    }

    /**
     * Las acciones destructivas o de acceso a datos de terceros se conservan
     * más tiempo (SECURITY.md §6).
     */
    public function isSensitive(): bool
    {
        return in_array($this, [
            self::EMAIL_ACCOUNT_CONNECTED,
            self::EMAIL_ACCOUNT_DELETED,
            self::DOCUMENT_VIEWED,
            self::DOCUMENT_DOWNLOADED,
            self::DOCUMENT_DELETED,
            self::DATA_EXPORTED,
            self::DATA_PURGED,
            self::USER_PASSWORD_CHANGED,
            self::USER_LOGIN_FAILED,
        ], true);
    }
}
