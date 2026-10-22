<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Añade el plan contratado a la organización (Fase 17, D-64).
 *
 * La columna va con valor por defecto `free`: las organizaciones que ya
 * existen no han contratar nada todavía, así que arrancan en el plan
 * gratuito. Con el valor por defecto en la base de datos, además, la
 * migración funciona con datos dentro y sin tocar una sola fila a mano.
 */
final class Version20261022105655 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade la columna `plan` a la tabla `organization` (Fase 17).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE organization ADD plan VARCHAR(20) NOT NULL DEFAULT 'free'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization DROP plan');
    }
}
