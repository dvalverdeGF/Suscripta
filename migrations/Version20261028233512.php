<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tablas del cobro (Fase 24).
 *
 * ## `payment_event` es la idempotencia
 *
 * La pasarela reintenta hasta recibir un 200, así que el mismo evento llega
 * varias veces. El índice único de `external_event_id` es lo que lo impide:
 * la reserva se hace con un `INSERT` que choca contra él y el segundo intento
 * no aplica nada. `applied` distingue los que se aplicaron de los que se
 * ignoraron a propósito (tipo desconocido, sin organización…), y `note` guarda
 * el motivo, que es lo que se mira cuando un cobro «no se ha reflejado».
 *
 * `created_at` lleva índice porque la tabla crece con cada evento de Stripe
 * —incluidos los cientos de tipos que no se usan— y hay una purga por
 * antigüedad.
 *
 * ## `sales_invoice` es la factura que emite Suscripta
 *
 * No tiene nada que ver con las facturas de `invoice`, que son las que el
 * cliente recibe de sus proveedores y se descubren en el correo (D-86). El
 * índice único por `external_invoice_id` hace que el mismo cobro no se apunte
 * dos veces, y el índice por organización y fecha es el que usa el panel.
 *
 * `subscription_id` es un UUID suelto y no una clave foránea, por el mismo
 * criterio que el resto del proyecto: ningún módulo toca las entidades de
 * otro, y una factura tiene que sobrevivir a que se borre la suscripción.
 *
 * `issued_at` y `paid_at` son `DATE` y no `TIMESTAMP`: una factura se emite un
 * día, no a una hora, y comparar fechas con zonas horarias para agrupar por
 * mes acaba en facturas que caen en el mes equivocado.
 */
final class Version20261028233512 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fase 24: eventos de pago (idempotencia del webhook) y facturas de venta.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payment_event (id UUID NOT NULL, external_event_id VARCHAR(120) NOT NULL, type VARCHAR(80) NOT NULL, applied BOOLEAN NOT NULL, note VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_payment_event_created ON payment_event (created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_payment_event_external ON payment_event (external_event_id)');
        $this->addSql('CREATE TABLE sales_invoice (id UUID NOT NULL, organization_id UUID NOT NULL, subscription_id UUID DEFAULT NULL, external_invoice_id VARCHAR(120) NOT NULL, number VARCHAR(60) DEFAULT NULL, amount_minor INT NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(20) NOT NULL, issued_at DATE NOT NULL, paid_at DATE DEFAULT NULL, hosted_url VARCHAR(500) DEFAULT NULL, pdf_url VARCHAR(500) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_sales_invoice_organization_issued ON sales_invoice (organization_id, issued_at)');
        $this->addSql('CREATE INDEX idx_sales_invoice_subscription ON sales_invoice (subscription_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_sales_invoice_external ON sales_invoice (external_invoice_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE payment_event');
        $this->addSql('DROP TABLE sales_invoice');
    }
}
