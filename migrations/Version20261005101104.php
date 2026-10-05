<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sincronización incremental y ingesta por reenvío (Fase 8).
 *
 * Añade el cursor de sincronización por carpeta, el origen del mensaje, el
 * contador de recuperación progresiva y los campos de reenvío de la cuenta.
 *
 * Las columnas nuevas se crean con un valor por defecto y luego se retira, para
 * que las filas ya existentes queden con un valor válido en lugar de fallar.
 */
final class Version20261005101104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cursor de sincronización IMAP, origen del mensaje y campos de reenvío';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_sync_cursor (id UUID NOT NULL, organization_id UUID NOT NULL, email_account_id UUID NOT NULL, folder VARCHAR(120) NOT NULL, uid_validity INT NOT NULL, last_seen_uid INT NOT NULL, backfill_cursor INT DEFAULT NULL, last_sync_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_email_sync_cursor_organization ON email_sync_cursor (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_email_sync_cursor_account_folder ON email_sync_cursor (email_account_id, folder)');

        $this->addSql('ALTER TABLE email_account ADD forwarding_address VARCHAR(180) DEFAULT NULL');
        $this->addSql("ALTER TABLE email_account ADD forwarding_senders JSON DEFAULT '[]'::json NOT NULL");
        $this->addSql('ALTER TABLE email_account ALTER forwarding_senders DROP DEFAULT');
        $this->addSql('ALTER TABLE email_account ADD forwarding_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE email_account ALTER forwarding_enabled DROP DEFAULT');

        $this->addSql("ALTER TABLE email_message ADD source VARCHAR(20) DEFAULT 'imap' NOT NULL");
        $this->addSql('ALTER TABLE email_message ALTER source DROP DEFAULT');
        $this->addSql('ALTER TABLE email_message ALTER uid DROP NOT NULL');

        $this->addSql('ALTER TABLE email_sync_run ADD messages_backfilled INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE email_sync_run ALTER messages_backfilled DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE email_sync_cursor');
        $this->addSql('ALTER TABLE email_account DROP forwarding_address');
        $this->addSql('ALTER TABLE email_account DROP forwarding_senders');
        $this->addSql('ALTER TABLE email_account DROP forwarding_enabled');
        $this->addSql('ALTER TABLE email_message DROP source');
        $this->addSql('ALTER TABLE email_message ALTER uid SET NOT NULL');
        $this->addSql('ALTER TABLE email_sync_run DROP messages_backfilled');
    }
}
