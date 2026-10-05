<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Propuestas de descubrimiento (`discovery`) y sus evidencias
 * (`discovery_evidence`).
 *
 * `Discovery` es tenant-scoped, no cuenta-scoped (D-27): la misma factura puede
 * llegar por dos buzones de la organización y no debe proponerse dos veces. De
 * ahí el índice sobre `(organization_id, dedup_key)`, que es la consulta de
 * deduplicación.
 */
final class Version20261005040830 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea las tablas de propuestas de descubrimiento y sus evidencias.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE discovery (id UUID NOT NULL, organization_id UUID NOT NULL, type VARCHAR(30) NOT NULL, status VARCHAR(20) NOT NULL, confidence VARCHAR(20) NOT NULL, confidence_score SMALLINT NOT NULL, match_score SMALLINT NOT NULL, match_reasons JSON NOT NULL, proposed_data JSON NOT NULL, matched_service_id UUID DEFAULT NULL, source_email_message_id UUID DEFAULT NULL, extraction_tier VARCHAR(20) DEFAULT NULL, ai_used BOOLEAN NOT NULL, detected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, reviewed_by_user_id UUID DEFAULT NULL, resulting_service_id UUID DEFAULT NULL, dedup_key VARCHAR(255) NOT NULL, notes TEXT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_discovery_organization_status ON discovery (organization_id, status)');
        $this->addSql('CREATE INDEX idx_discovery_source_message ON discovery (source_email_message_id)');
        $this->addSql('CREATE INDEX idx_discovery_dedup_key ON discovery (organization_id, dedup_key)');
        $this->addSql('CREATE TABLE discovery_evidence (id UUID NOT NULL, discovery_id UUID NOT NULL, email_message_id UUID DEFAULT NULL, document_id UUID DEFAULT NULL, weight SMALLINT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_discovery_evidence_discovery ON discovery_evidence (discovery_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE discovery');
        $this->addSql('DROP TABLE discovery_evidence');
    }
}
