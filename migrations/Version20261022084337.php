<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fase 14: presupuesto de IA por organización y mes (D-55).
 *
 * La tabla guarda **la política**, no el gasto: el tope mensual y qué hacer
 * al agotarlo. Lo gastado vive en `ai_usage`, que es el libro mayor real. Si
 * aquí también se guardara la suma gastada, la tabla sería una segunda fuente
 * de verdad y las dos se contradirían en cuanto una escritura fallara.
 *
 * El periodo es un texto `YYYY-MM`, que es la misma clave que usan las
 * facturas de los proveedores de IA: un mes de Suscripta y un mes de la
 * factura se comparan sin traducción.
 *
 * La restricción única sobre `(organization_id, period)` garantiza que cada
 * organización tenga **un** presupuesto por mes. Es lo que permite que la
 * lectura del presupuesto no tenga que desambiguar entre varias filas.
 */
final class Version20261022084337 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fase 14: tabla ai_budget (presupuesto de IA por organización y mes)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ai_budget (id UUID NOT NULL, organization_id UUID NOT NULL, period VARCHAR(7) NOT NULL, limit_minor INT NOT NULL, mode VARCHAR(10) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_ai_budget_organization_period ON ai_budget (organization_id, period)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_budget');
    }
}
