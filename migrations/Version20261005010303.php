<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Catálogo, servicios, precios, eventos y auditoría.
 *
 * `service_price` guarda el historial completo: el precio vigente es la fila con
 * `valid_to IS NULL` (DECISIONS.md D-13).
 */
final class Version20261005010303 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catálogo, servicios, precios, eventos y auditoría';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_log (id UUID NOT NULL, organization_id UUID DEFAULT NULL, actor_user_id UUID DEFAULT NULL, actor_type VARCHAR(20) NOT NULL, action VARCHAR(60) NOT NULL, target_type VARCHAR(60) DEFAULT NULL, target_id VARCHAR(64) DEFAULT NULL, metadata JSON NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_audit_log_organization_created ON audit_log (organization_id, created_at)');
        $this->addSql('CREATE INDEX idx_audit_log_action_created ON audit_log (action, created_at)');
        $this->addSql('CREATE TABLE category (id UUID NOT NULL, organization_id UUID DEFAULT NULL, name VARCHAR(80) NOT NULL, slug VARCHAR(80) NOT NULL, color VARCHAR(20) NOT NULL, icon VARCHAR(40) DEFAULT NULL, is_system BOOLEAN NOT NULL, sort_order SMALLINT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_category_org_slug ON category (organization_id, slug)');
        $this->addSql('CREATE TABLE provider (id UUID NOT NULL, organization_id UUID DEFAULT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(120) NOT NULL, aliases JSON NOT NULL, website VARCHAR(255) DEFAULT NULL, logo_path VARCHAR(255) DEFAULT NULL, default_category_id UUID DEFAULT NULL, is_system BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_provider_org_slug ON provider (organization_id, slug)');
        $this->addSql('CREATE TABLE provider_identity (id UUID NOT NULL, type VARCHAR(30) NOT NULL, value VARCHAR(255) NOT NULL, confidence SMALLINT NOT NULL, source VARCHAR(20) NOT NULL, hit_count INT NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, provider_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_provider_identity_provider ON provider_identity (provider_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_provider_identity_type_value ON provider_identity (type, value)');
        $this->addSql('CREATE TABLE provider_parser (id UUID NOT NULL, key VARCHAR(60) NOT NULL, version SMALLINT NOT NULL, enabled BOOLEAN NOT NULL, config JSON NOT NULL, success_count INT NOT NULL, failure_count INT NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, provider_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_provider_parser_key_version ON provider_parser (provider_id, key, version)');
        $this->addSql('CREATE INDEX IDX_788320E3A53A8AA ON provider_parser (provider_id)');
        $this->addSql('CREATE TABLE service (id UUID NOT NULL, organization_id UUID NOT NULL, provider_id UUID DEFAULT NULL, category_id UUID DEFAULT NULL, name VARCHAR(160) NOT NULL, plan_name VARCHAR(160) DEFAULT NULL, status VARCHAR(20) NOT NULL, currency VARCHAR(3) NOT NULL, billing_period VARCHAR(20) NOT NULL, billing_interval_count SMALLINT NOT NULL, started_at DATE DEFAULT NULL, next_charge_at DATE DEFAULT NULL, renewal_at DATE DEFAULT NULL, notice_period_days SMALLINT DEFAULT NULL, auto_renews BOOLEAN NOT NULL, commitment_end_at DATE DEFAULT NULL, cancelled_at DATE DEFAULT NULL, payment_method_label VARCHAR(80) DEFAULT NULL, notes TEXT DEFAULT NULL, source VARCHAR(20) NOT NULL, created_by_user_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_service_organization_status ON service (organization_id, status)');
        $this->addSql('CREATE INDEX idx_service_next_charge ON service (organization_id, next_charge_at)');
        $this->addSql('CREATE TABLE service_event (id UUID NOT NULL, type VARCHAR(30) NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, data JSON NOT NULL, actor_user_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, service_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_service_event_service_occurred ON service_event (service_id, occurred_at)');
        $this->addSql('CREATE INDEX IDX_92DCE740ED5CA9E6 ON service_event (service_id)');
        $this->addSql('CREATE TABLE service_price (id UUID NOT NULL, amount_minor INT NOT NULL, currency VARCHAR(3) NOT NULL, valid_from DATE NOT NULL, valid_to DATE DEFAULT NULL, source VARCHAR(20) NOT NULL, invoice_id UUID DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, service_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_service_price_service_valid ON service_price (service_id, valid_from, valid_to)');
        $this->addSql('CREATE INDEX IDX_63BACF3EED5CA9E6 ON service_price (service_id)');
        $this->addSql('ALTER TABLE provider_identity ADD CONSTRAINT FK_22D7872DA53A8AA FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE provider_parser ADD CONSTRAINT FK_788320E3A53A8AA FOREIGN KEY (provider_id) REFERENCES provider (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE service_event ADD CONSTRAINT FK_92DCE740ED5CA9E6 FOREIGN KEY (service_id) REFERENCES service (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE service_price ADD CONSTRAINT FK_63BACF3EED5CA9E6 FOREIGN KEY (service_id) REFERENCES service (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE provider_identity DROP CONSTRAINT FK_22D7872DA53A8AA');
        $this->addSql('ALTER TABLE provider_parser DROP CONSTRAINT FK_788320E3A53A8AA');
        $this->addSql('ALTER TABLE service_event DROP CONSTRAINT FK_92DCE740ED5CA9E6');
        $this->addSql('ALTER TABLE service_price DROP CONSTRAINT FK_63BACF3EED5CA9E6');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE provider');
        $this->addSql('DROP TABLE provider_identity');
        $this->addSql('DROP TABLE provider_parser');
        $this->addSql('DROP TABLE service');
        $this->addSql('DROP TABLE service_event');
        $this->addSql('DROP TABLE service_price');
    }
}
