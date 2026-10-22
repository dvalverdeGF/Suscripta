<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Índices para el panel de observabilidad (Fase 17).
 *
 * Las estadísticas de sincronización y de extracción filtran por organización
 * y por ventana temporal. Sin estos índices, cada visita al panel recorre
 * todas las pasadas y todos los mensajes de todos los clientes: el coste
 * crece con el número de organizaciones, no con el de la que pregunta.
 */
final class Version20261023140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Índices de organización y fecha para el panel de observabilidad.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_email_message_organization_received ON email_message (organization_id, received_at)');
        $this->addSql('CREATE INDEX idx_email_sync_run_organization_started ON email_sync_run (organization_id, started_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_email_message_organization_received');
        $this->addSql('DROP INDEX idx_email_sync_run_organization_started');
    }
}
