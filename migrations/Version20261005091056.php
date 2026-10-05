<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Avisos y notificaciones (Fase 6).
 *
 * `alert` guarda el aviso y su estado; `notification` guarda cada intento de
 * entrega por un canal, para que un correo que no salió sea visible en lugar de
 * desaparecer. `notification_preference` solo contiene desviaciones respecto a
 * los valores por defecto, así que añadir un tipo de aviso no obliga a migrar
 * las preferencias de nadie.
 *
 * Las claves de deduplicación son únicas por organización: es lo que hace que
 * generar avisos dos veces no duplique nada.
 */
final class Version20261005091056 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea las tablas de avisos, notificaciones y preferencias.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE alert (id UUID NOT NULL, organization_id UUID NOT NULL, service_id UUID DEFAULT NULL, discovery_id UUID DEFAULT NULL, type VARCHAR(30) NOT NULL, severity VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, title VARCHAR(160) NOT NULL, message TEXT NOT NULL, due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, dedup_key VARCHAR(255) NOT NULL, metadata JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, resolved_by_user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_alert_organization_status ON alert (organization_id, status)');
        $this->addSql('CREATE INDEX idx_alert_service ON alert (service_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_alert_dedup_key ON alert (organization_id, dedup_key)');
        $this->addSql('CREATE TABLE notification (id UUID NOT NULL, organization_id UUID NOT NULL, alert_id UUID NOT NULL, user_id UUID NOT NULL, channel VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, dedup_key VARCHAR(255) NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, error VARCHAR(500) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_notification_organization ON notification (organization_id, created_at)');
        $this->addSql('CREATE INDEX idx_notification_alert ON notification (alert_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_dedup_key ON notification (organization_id, dedup_key)');
        $this->addSql('CREATE TABLE notification_preference (id UUID NOT NULL, organization_id UUID NOT NULL, user_id UUID NOT NULL, alert_type VARCHAR(30) NOT NULL, channel VARCHAR(20) NOT NULL, enabled BOOLEAN NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_preference ON notification_preference (user_id, alert_type, channel)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE alert');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE notification_preference');
    }
}
