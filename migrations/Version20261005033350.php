<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Módulo Mailbox: cuentas IMAP, mensajes, ejecuciones de sincronización y
 * traza de transiciones del pipeline (ARCHITECTURE.md §4.5 y §13).
 */
final class Version20261005033350 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea las tablas del módulo Mailbox (cuentas IMAP, mensajes, sincronizaciones y traza del pipeline).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_account (id UUID NOT NULL, organization_id UUID NOT NULL, provider VARCHAR(20) NOT NULL, email_address VARCHAR(180) NOT NULL, display_name VARCHAR(120) DEFAULT NULL, status VARCHAR(20) NOT NULL, credentials_encrypted TEXT DEFAULT NULL, imap_host VARCHAR(180) DEFAULT NULL, imap_port SMALLINT DEFAULT NULL, imap_encryption VARCHAR(20) DEFAULT NULL, imap_username VARCHAR(180) DEFAULT NULL, imap_folder VARCHAR(120) NOT NULL, last_sync_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_sync_status VARCHAR(20) DEFAULT NULL, last_sync_error TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_email_account_organization ON email_account (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_email_account_org_address ON email_account (organization_id, email_address)');
        $this->addSql('CREATE TABLE email_message (id UUID NOT NULL, organization_id UUID NOT NULL, email_account_id UUID NOT NULL, folder VARCHAR(120) NOT NULL, uid INT NOT NULL, message_id VARCHAR(255) DEFAULT NULL, from_address VARCHAR(180) DEFAULT NULL, from_name VARCHAR(180) DEFAULT NULL, reply_to VARCHAR(180) DEFAULT NULL, sender_domain VARCHAR(180) DEFAULT NULL, to_addresses JSON NOT NULL, subject VARCHAR(500) DEFAULT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, size_bytes INT DEFAULT NULL, content_type VARCHAR(120) DEFAULT NULL, has_attachments BOOLEAN NOT NULL, attachment_names JSON NOT NULL, attachment_types JSON NOT NULL, content_hash VARCHAR(64) DEFAULT NULL, body_excerpt TEXT DEFAULT NULL, billing_score SMALLINT NOT NULL, billing_reasons JSON NOT NULL, processing_state VARCHAR(20) NOT NULL, classification VARCHAR(30) NOT NULL, classification_confidence SMALLINT NOT NULL, extraction_tier VARCHAR(20) DEFAULT NULL, extractor_used VARCHAR(80) DEFAULT NULL, ai_used BOOLEAN NOT NULL, ai_cost_minor INT NOT NULL, attempts SMALLINT NOT NULL, last_error TEXT DEFAULT NULL, processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_email_message_account_message_id ON email_message (email_account_id, message_id)');
        $this->addSql('CREATE INDEX idx_email_message_content_hash ON email_message (content_hash)');
        $this->addSql('CREATE INDEX idx_email_message_organization_state ON email_message (organization_id, processing_state)');
        $this->addSql('CREATE UNIQUE INDEX uniq_email_message_account_folder_uid ON email_message (email_account_id, folder, uid)');
        $this->addSql('CREATE TABLE email_sync_run (id UUID NOT NULL, organization_id UUID NOT NULL, email_account_id UUID NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, status VARCHAR(20) NOT NULL, messages_seen INT NOT NULL, messages_processed INT NOT NULL, messages_skipped INT NOT NULL, discoveries_created INT NOT NULL, error TEXT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_email_sync_run_account ON email_sync_run (email_account_id, started_at)');
        $this->addSql('CREATE TABLE message_processing_event (id UUID NOT NULL, email_message_id UUID NOT NULL, from_state VARCHAR(20) DEFAULT NULL, to_state VARCHAR(20) NOT NULL, reason VARCHAR(255) NOT NULL, extractor VARCHAR(80) DEFAULT NULL, tier VARCHAR(20) DEFAULT NULL, ai_usage_id UUID DEFAULT NULL, duration_ms INT DEFAULT NULL, data JSON NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_message_processing_event_message ON message_processing_event (email_message_id, occurred_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE email_account');
        $this->addSql('DROP TABLE email_message');
        $this->addSql('DROP TABLE email_sync_run');
        $this->addSql('DROP TABLE message_processing_event');
    }
}
