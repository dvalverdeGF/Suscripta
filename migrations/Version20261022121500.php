<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Guarda si la guía de bienvenida ya se ha terminado (Fase 17).
 *
 * La columna es nullable a propósito: `null` significa «todavía no ha
 * terminado», que es un estado de verdad y no un `false` escondido. Así el
 * panel puede preguntar «¿cuándo la terminó?» sin adivinar.
 */
final class Version20261022121500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade la columna `onboarding_completed_at` a la tabla `app_user` (Fase 17).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD onboarding_completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP onboarding_completed_at');
    }
}
