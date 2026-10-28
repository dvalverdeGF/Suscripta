<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Catálogo comercial: planes, precios y características (Fase 22).
 *
 * ## Por qué tres tablas y no una
 *
 * Un plan tiene **muchos** precios a lo largo del tiempo y **muchas**
 * características, y las tres cosas se leen por separado: la tarjeta de precios
 * necesita todos los planes con su precio vigente, la ficha necesita el
 * historial de uno solo. Con una tabla única cada lectura tendría que deduplicar
 * filas repetidas.
 *
 * ## Por qué el precio no se sobrescribe
 *
 * `valid_from` / `valid_to` convierten el precio en un intervalo, igual que
 * `service_price` (D-13). La factura de marzo tiene que poder explicarse con el
 * precio de marzo aunque hoy cueste otra cosa; borrar la fila vieja dejaría la
 * factura sin referencia. «Vigente» es `valid_to IS NULL`, y publicar un precio
 * nuevo cierra el anterior del mismo periodo y moneda.
 *
 * ## Por qué el código es único y no un texto libre
 *
 * `plan.code` es el `OrganizationPlan` que aplica el producto (`free`, `pro`).
 * Un índice único impide dos fichas comerciales del mismo plan, que dejarían al
 * checkout sin saber cuál cobrar. Los **límites** no están aquí: siguen en
 * `entitlements.yaml`, que es la única fuente que el producto consulta (D-64).
 *
 * ## Por qué se borra en cascada
 *
 * Precios y características no tienen vida fuera de su plan: si una ficha
 * desaparece (cosa que la interfaz no permite, pero un `DELETE` manual sí), sus
 * filas hijas no pueden quedar apuntando a nada. Lo que **no** se borra desde la
 * aplicación es el plan: se retira con `is_active = false`, porque las
 * suscripciones que lo referencian siguen existiendo.
 */
final class Version20261028230248 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catálogo comercial: plan, plan_price y plan_feature (fase 22).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE plan (id UUID NOT NULL, code VARCHAR(20) NOT NULL, name VARCHAR(80) NOT NULL, tagline VARCHAR(160) DEFAULT NULL, description TEXT DEFAULT NULL, is_active BOOLEAN NOT NULL, is_highlighted BOOLEAN NOT NULL, position SMALLINT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_plan_position ON plan (position)');
        $this->addSql('CREATE UNIQUE INDEX uniq_plan_code ON plan (code)');
        $this->addSql('CREATE TABLE plan_feature (id UUID NOT NULL, label VARCHAR(120) NOT NULL, included BOOLEAN NOT NULL, position SMALLINT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, plan_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_plan_feature_plan_position ON plan_feature (plan_id, position)');
        $this->addSql('CREATE INDEX IDX_A1683D6EE899029B ON plan_feature (plan_id)');
        $this->addSql('CREATE TABLE plan_price (id UUID NOT NULL, amount_minor INT NOT NULL, currency VARCHAR(3) NOT NULL, billing_period VARCHAR(20) NOT NULL, valid_from DATE NOT NULL, valid_to DATE DEFAULT NULL, external_price_id VARCHAR(120) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, plan_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_plan_price_plan_valid ON plan_price (plan_id, valid_from, valid_to)');
        $this->addSql('CREATE INDEX IDX_996E6725E899029B ON plan_price (plan_id)');
        $this->addSql('ALTER TABLE plan_feature ADD CONSTRAINT FK_A1683D6EE899029B FOREIGN KEY (plan_id) REFERENCES plan (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE plan_price ADD CONSTRAINT FK_996E6725E899029B FOREIGN KEY (plan_id) REFERENCES plan (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE plan_feature DROP CONSTRAINT FK_A1683D6EE899029B');
        $this->addSql('ALTER TABLE plan_price DROP CONSTRAINT FK_996E6725E899029B');
        $this->addSql('DROP TABLE plan');
        $this->addSql('DROP TABLE plan_feature');
        $this->addSql('DROP TABLE plan_price');
    }
}
