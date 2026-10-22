<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fase 15: marca de inferencia en las propuestas (D-59).
 *
 * Una propuesta puede ser un **hecho** (lo que el sistema ha leído en una
 * factura) o una **inferencia** (lo que ha deducido del inventario: un
 * posible duplicado, un cobro que no ha llegado, un servicio sin actividad).
 *
 * La distinción vive en una columna porque la interfaz la necesita para dos
 * cosas a la vez: **filtrar** la bandeja y **presentar** la propuesta como
 * sugerencia en lugar de como verdad. Si solo estuviera en el JSON de
 * `proposed_data`, el filtrado sería un filtro en memoria sobre una columna
 * sin índice.
 *
 * El valor por defecto es `false` porque hasta la fase 15 todas las propuestas
 * existentes eran hechos leídos de documentos.
 */
final class Version20261022092002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fase 15: columna discovery.is_inference (marca de inferencia)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discovery ADD is_inference BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discovery DROP is_inference');
    }
}
