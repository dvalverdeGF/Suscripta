<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Panel de integraciones y registro de gasto en IA (Fase 11).
 *
 * Son dos tablas nuevas, así que no hay filas previas que rellenar: la
 * configuración de integraciones se llena desde el panel, y el registro de uso
 * de IA se llena solo a medida que el pipeline llama a los modelos.
 *
 * `integration` es **multi-tenant**: la configuración de IA es por cliente, no
 * global, así que la unicidad es por `(organization_id, integration_key)` y no
 * por `integration_key` sola. Dos clientes pueden usar Groq con claves
 * distintas sin interferirse.
 *
 * `ai_usage` es también multi-tenant y existe para que el coste de la IA sea
 * un dato consultable (D-36): cuánto se ha gastado, con qué proveedor y para
 * qué necesidad.
 */
final class Version20261020232102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Configuración de integraciones por organización y registro de uso de IA';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_usage (id UUID NOT NULL, organization_id UUID NOT NULL, provider_key VARCHAR(60) NOT NULL, purpose VARCHAR(40) NOT NULL, tier VARCHAR(20) NOT NULL, model VARCHAR(120) NOT NULL, prompt_tokens INT NOT NULL, completion_tokens INT NOT NULL, cost_minor INT NOT NULL, successful BOOLEAN NOT NULL, duration_ms INT NOT NULL, entity_type VARCHAR(60) DEFAULT NULL, entity_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_ai_usage_organization_created ON ai_usage (organization_id, created_at)');
        $this->addSql('CREATE TABLE integration (id UUID NOT NULL, organization_id UUID NOT NULL, integration_key VARCHAR(100) NOT NULL, label VARCHAR(255) NOT NULL, base_url VARCHAR(500) DEFAULT NULL, credentials_encrypted TEXT DEFAULT NULL, credential_key_id VARCHAR(255) DEFAULT NULL, settings JSON NOT NULL, is_enabled BOOLEAN NOT NULL, last_success_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_error VARCHAR(500) DEFAULT NULL, last_error_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_integration_enabled ON integration (is_enabled)');
        $this->addSql('CREATE UNIQUE INDEX uniq_integration_key ON integration (organization_id, integration_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_usage');
        $this->addSql('DROP TABLE integration');
    }
}
