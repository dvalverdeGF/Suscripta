<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pasa el coste de la IA de céntimos a microcéntimos (Fase 17, D-68).
 *
 * ## Por qué
 *
 * Una llamada a un modelo económico cuesta céntimas: con Groq, un millón de
 * tokens de entrada cuesta 0,59 €. Guardando el coste en céntimos, casi
 * todas las llamadas se registraban como coste cero y el panel de gasto
 * enseñaba «0,03 €» de un gasto real de «0,14 €».
 *
 * ## Qué hace
 *
 * Renombra las columnas y multiplica su valor por 10 000 (1 céntimo =
 * 10 000 microcéntimos). El tipo pasa a `BIGINT` porque el total acumulado
 * puede crecer mucho: 1 € son 1 000 000 microcéntimos.
 *
 * La conversión es exacta y no pierde información: de microcéntimos a
 * céntimos solo se redondea cuando se pinta.
 */
final class Version20261023090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convierte el coste de la IA a microcéntimos (`cost_minor` → `cost_micro`) (D-68).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_usage RENAME COLUMN cost_minor TO cost_micro');
        $this->addSql('ALTER TABLE ai_usage ALTER COLUMN cost_micro TYPE BIGINT');
        $this->addSql('UPDATE ai_usage SET cost_micro = cost_micro * 10000');

        $this->addSql('ALTER TABLE email_message RENAME COLUMN ai_cost_minor TO ai_cost_micro');
        $this->addSql('ALTER TABLE email_message ALTER COLUMN ai_cost_micro TYPE BIGINT');
        $this->addSql('UPDATE email_message SET ai_cost_micro = ai_cost_micro * 10000');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE ai_usage SET cost_micro = cost_micro / 10000');
        $this->addSql('ALTER TABLE ai_usage ALTER COLUMN cost_micro TYPE INTEGER');
        $this->addSql('ALTER TABLE ai_usage RENAME COLUMN cost_micro TO cost_minor');

        $this->addSql('UPDATE email_message SET ai_cost_micro = ai_cost_micro / 10000');
        $this->addSql('ALTER TABLE email_message ALTER COLUMN ai_cost_micro TYPE INTEGER');
        $this->addSql('ALTER TABLE email_message RENAME COLUMN ai_cost_micro TO ai_cost_minor');
    }
}
