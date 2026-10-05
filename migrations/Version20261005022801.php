<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Solicitudes de restablecimiento de contraseña.
 *
 * Se guarda el hash del token, nunca el token en claro (SECURITY.md §3).
 */
final class Version20261005022801 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea la tabla de solicitudes de restablecimiento de contraseña.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE password_reset_request (id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, requested_ip VARCHAR(45) DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_password_reset_token_hash ON password_reset_request (token_hash)');
        $this->addSql('CREATE INDEX IDX_C5D0A95AA76ED395 ON password_reset_request (user_id)');
        $this->addSql('ALTER TABLE password_reset_request ADD CONSTRAINT FK_C5D0A95AA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE password_reset_request DROP CONSTRAINT FK_C5D0A95AA76ED395');
        $this->addSql('DROP TABLE password_reset_request');
    }
}
