<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Webhooks de salida (Fase 20).
 *
 * Dos tablas porque son dos cosas distintas: `webhook` es la configuración
 * (una URL, su secreto y a qué eventos se suscribe) y `webhook_delivery` es el
 * historial de intentos. Meter el historial en un JSON dentro de `webhook`
 * habría hecho imposible buscar «qué entregas están pendientes ahora mismo»,
 * que es la única consulta que el sistema hace de verdad.
 *
 * El secreto se guarda cifrado y no como huella: hay que leerlo en claro para
 * firmar cada entrega, así que aquí la protección es el cifrado en reposo.
 *
 * `idempotency_key` es única porque es lo que permite al receptor descartar
 * duplicados sin pensar. Los dos índices de `webhook_delivery` cubren las dos
 * consultas del worker: «las de este suscriptor» (el panel) y «las vencidas»
 * (el reintento).
 */
final class Version20261028215037 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabla de suscriptores de webhooks y de sus entregas con reintentos.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE webhook (id UUID NOT NULL, organization_id UUID NOT NULL, name VARCHAR(100) NOT NULL, url VARCHAR(2048) NOT NULL, secret_encrypted TEXT NOT NULL, events JSON NOT NULL, active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_delivery_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_webhook_organization ON webhook (organization_id)');
        $this->addSql('CREATE TABLE webhook_delivery (id UUID NOT NULL, organization_id UUID NOT NULL, webhook_id UUID NOT NULL, event VARCHAR(50) NOT NULL, payload JSON NOT NULL, status VARCHAR(20) NOT NULL, attempts SMALLINT NOT NULL, next_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, response_status SMALLINT DEFAULT NULL, last_error VARCHAR(500) DEFAULT NULL, idempotency_key VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_webhook_delivery_webhook ON webhook_delivery (webhook_id)');
        $this->addSql('CREATE INDEX idx_webhook_delivery_status ON webhook_delivery (status, next_attempt_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_webhook_delivery_idempotency ON webhook_delivery (idempotency_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE webhook');
        $this->addSql('DROP TABLE webhook_delivery');
    }
}
