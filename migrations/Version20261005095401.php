<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Origen de la factura: registrada a mano o detectada en el correo.
 *
 * Hace falta porque una factura puede existir sin documento (D-19), y entonces
 * no hay ningún `document.source` que diga de dónde salió. Es también la
 * métrica que mide la propuesta de valor (PRODUCT.md §12).
 */
final class Version20261005095401 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade invoice.source (manual | email_discovery).';
    }

    public function up(Schema $schema): void
    {
        // Con valor por defecto para no romper las filas ya existentes; después
        // se retira, porque el valor lo fija siempre el dominio.
        $this->addSql("ALTER TABLE invoice ADD source VARCHAR(20) DEFAULT 'manual' NOT NULL");
        $this->addSql('ALTER TABLE invoice ALTER source DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP source');
    }
}
