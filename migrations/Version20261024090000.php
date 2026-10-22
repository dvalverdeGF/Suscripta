<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tabla de claves de API (Fase 18).
 *
 * De la clave solo se guarda la huella (HMAC-SHA-256), nunca el texto en
 * claro. El prefijo visible se guarda aparte para poder reconocer la clave
 * en el panel sin exponerla.
 *
 * El índice por organización sirve al filtro de inquilino: sin él, cada
 * autenticación de la API recorrería todas las claves de todos los clientes.
 */
final class Version20261024090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabla de claves de API con su huella, ámbitos y caducidad.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_key (id UUID NOT NULL, organization_id UUID NOT NULL, name VARCHAR(100) NOT NULL, prefix VARCHAR(20) NOT NULL, secret_hash VARCHAR(64) NOT NULL, scopes JSON NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_by_user_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_api_key_organization ON api_key (organization_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_api_key_secret_hash ON api_key (secret_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE api_key');
    }
}
