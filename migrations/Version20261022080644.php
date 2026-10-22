<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fase 13: conocimiento aprendido del buzón (ARCHITECTURE.md §13.12).
 *
 * Una fila es una afirmación comprobable sobre un buzón concreto: «este
 * remitente es este proveedor», «este asunto es una factura», «este remitente
 * no me interesa». La tabla no se relaciona con `provider_identity` a
 * propósito: el conocimiento global y el del buzón son cosas distintas, y
 * mezclarlos produciría contaminación entre usuarios (D-33).
 */
final class Version20261022080644 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fase 13: tabla mailbox_knowledge_entry (conocimiento aprendido del buzón)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mailbox_knowledge_entry (id UUID NOT NULL, email_account_id UUID NOT NULL, organization_id UUID NOT NULL, type VARCHAR(30) NOT NULL, value VARCHAR(255) NOT NULL, provider_id UUID DEFAULT NULL, document_type VARCHAR(20) DEFAULT NULL, billing_period VARCHAR(20) DEFAULT NULL, confidence SMALLINT NOT NULL, hit_count INT NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, promoted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, source VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_mailbox_knowledge_account ON mailbox_knowledge_entry (email_account_id)');
        $this->addSql('CREATE INDEX idx_mailbox_knowledge_account_type ON mailbox_knowledge_entry (email_account_id, type)');
        $this->addSql('CREATE UNIQUE INDEX uniq_mailbox_knowledge_account_type_value ON mailbox_knowledge_entry (email_account_id, type, value)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE mailbox_knowledge_entry');
    }
}
