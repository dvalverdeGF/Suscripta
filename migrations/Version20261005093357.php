<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Documentos y facturas (ARCHITECTURE.md §4.4).
 *
 * `document` guarda el fichero y sus metadatos; `invoice` guarda el hecho
 * económico. Se separan a propósito (D-19): una factura puede existir sin
 * fichero y un fichero puede no ser una factura.
 *
 * La restricción única `(organization_id, checksum_sha256)` es la que impide
 * que la misma factura, llegada por dos buzones, se guarde dos veces (D-27).
 */
final class Version20261005093357 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea las tablas de documentos y facturas.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document (id UUID NOT NULL, organization_id UUID NOT NULL, service_id UUID DEFAULT NULL, invoice_id UUID DEFAULT NULL, email_message_id UUID DEFAULT NULL, original_filename VARCHAR(255) NOT NULL, storage_driver VARCHAR(40) NOT NULL, storage_key VARCHAR(255) NOT NULL, mime_type VARCHAR(120) NOT NULL, size_bytes INT NOT NULL, checksum_sha256 VARCHAR(64) NOT NULL, type VARCHAR(20) NOT NULL, source VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_document_organization_created ON document (organization_id, created_at)');
        $this->addSql('CREATE INDEX idx_document_service ON document (service_id)');
        $this->addSql('CREATE INDEX idx_document_invoice ON document (invoice_id)');
        $this->addSql('CREATE INDEX idx_document_email_message ON document (email_message_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_document_checksum ON document (organization_id, checksum_sha256)');
        $this->addSql('CREATE TABLE invoice (id UUID NOT NULL, organization_id UUID NOT NULL, service_id UUID DEFAULT NULL, provider_id UUID DEFAULT NULL, document_id UUID DEFAULT NULL, number VARCHAR(120) DEFAULT NULL, issued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, total_amount_minor INT NOT NULL, currency VARCHAR(3) NOT NULL, period_start TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, period_end TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_invoice_organization_issued ON invoice (organization_id, issued_at)');
        $this->addSql('CREATE INDEX idx_invoice_service ON invoice (service_id)');
        $this->addSql('CREATE INDEX idx_invoice_document ON invoice (document_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE document');
        $this->addSql('DROP TABLE invoice');
    }
}
