<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Caché de extracción por contenido (D-37).
 *
 * Es una tabla nueva, así que no hay filas previas que rellenar: la caché se
 * llena sola a medida que el pipeline procesa correos.
 */
final class Version20261005105737 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Caché de extracción por contenido, acotada por organización';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE extraction_cache (id UUID NOT NULL, organization_id UUID NOT NULL, content_hash VARCHAR(64) NOT NULL, document JSON NOT NULL, tier VARCHAR(20) NOT NULL, confidence SMALLINT NOT NULL, hit_count INT NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_extraction_cache_last_used ON extraction_cache (organization_id, last_used_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_extraction_cache_content ON extraction_cache (organization_id, content_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE extraction_cache');
    }
}
