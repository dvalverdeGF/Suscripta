<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tabla `subscription`: el contrato recurrente como entidad propia (fase 21).
 *
 * Hasta ahora `service` era el único término del dominio, y eso funcionaba
 * mientras lo único que se guardaba era lo que se paga. La fase 22 (catálogo) y
 * la 24 (cobro) necesitan distinguir el **producto** que se vende de la
 * **suscripción** que alguien tiene a ese producto: un plan puede cambiar de
 * precio sin tocar las suscripciones vivas, y una suscripción tiene estados de
 * cobro —`past_due`— que un servicio descubierto en el correo nunca tendrá.
 *
 * Tres decisiones que se ven en las columnas:
 *
 * - `plan_id` y `service_id` son **UUID sueltos, sin clave ajena**. `Plan` vivirá
 *   en el módulo `Catalog` y `Service` en `Services`; los módulos no se
 *   referencian entre sí por FK, igual que `service.provider_id` (D-78). Eso
 *   permite además que `plan_id` esté vacío hasta que exista catálogo.
 * - `name` es una **copia** del nombre en el momento del alta, no una lectura del
 *   plan. Si el plan se renombra, la suscripción de quien ya lo contrató sigue
 *   llamándose como se llamaba (D-79).
 * - No hay columna de importe: el precio vive en el catálogo y en las facturas.
 *   Congelar un precio aquí obligaría a decidir ahora qué hacer con las subidas,
 *   y eso se decide en la fase 24 con la pasarela delante.
 *
 * **Sin relleno de datos.** El plan de la fase preveía enlazar los servicios
 * `MANUAL` con su plan del catálogo, pero el catálogo es la fase 22 y todavía no
 * existe: no hay nada con lo que emparejarlos. El relleno se hace allí, cuando
 * haya planes, y por eso la tabla nace vacía y `service_id` es anulable.
 *
 * Los índices son los tres accesos que ya usa el repositorio: listar por
 * organización y estado, buscar la suscripción viva de un servicio y agrupar por
 * plan cuando el catálogo quiera saber cuántas suscripciones tiene cada uno.
 */
final class Version20261028224739 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea la tabla subscription (fase 21): el contrato recurrente, separado del servicio.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE subscription (id UUID NOT NULL, organization_id UUID NOT NULL, plan_id UUID DEFAULT NULL, service_id UUID DEFAULT NULL, name VARCHAR(160) NOT NULL, status VARCHAR(20) NOT NULL, currency VARCHAR(3) NOT NULL, billing_period VARCHAR(20) NOT NULL, billing_interval_count SMALLINT NOT NULL, started_at DATE NOT NULL, current_period_start DATE NOT NULL, current_period_end DATE NOT NULL, trial_ends_at DATE DEFAULT NULL, canceled_at DATE DEFAULT NULL, ends_at DATE DEFAULT NULL, external_reference VARCHAR(120) DEFAULT NULL, created_by_user_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_subscription_organization_status ON subscription (organization_id, status)');
        $this->addSql('CREATE INDEX idx_subscription_service ON subscription (service_id)');
        $this->addSql('CREATE INDEX idx_subscription_plan ON subscription (plan_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE subscription');
    }
}
