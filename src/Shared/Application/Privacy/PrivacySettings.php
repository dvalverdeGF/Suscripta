<?php

declare(strict_types=1);

namespace App\Shared\Application\Privacy;

use DateTimeImmutable;

use function sprintf;

/**
 * Política de retención de datos (config/packages/privacy.yaml).
 *
 * Son plazos, no constantes: cambiarlos es una decisión de producto y de
 * cumplimiento, y deben poder cambiarse sin tocar el código (SECURITY.md §6).
 *
 * Los plazos se aplican **por categoría de dato**, no por módulo: el correo es
 * evidencia temporal y se purga antes; los servicios son el producto y
 * sobreviven a la desconexión del buzón.
 */
final readonly class PrivacySettings
{
    public function __construct(
        public int $emailMessagesDays = 365,
        public int $emailEventsDays = 365,
        public int $documentsGraceDays = 30,
        public int $ignoredDiscoveriesDays = 90,
        public int $resolvedAlertsDays = 365,
        public int $auditLogDays = 730,
        public int $aiUsageDays = 730,
        public int $mailboxKnowledgeDays = 365,
        public int $extractionCacheDays = 30,
        public int $purgeBatchSize = 500,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            emailMessagesDays: (int) ($config['email_messages_days'] ?? 365),
            emailEventsDays: (int) ($config['email_events_days'] ?? 365),
            documentsGraceDays: (int) ($config['documents_grace_days'] ?? 30),
            ignoredDiscoveriesDays: (int) ($config['ignored_discoveries_days'] ?? 90),
            resolvedAlertsDays: (int) ($config['resolved_alerts_days'] ?? 365),
            auditLogDays: (int) ($config['audit_log_days'] ?? 730),
            aiUsageDays: (int) ($config['ai_usage_days'] ?? 730),
            mailboxKnowledgeDays: (int) ($config['mailbox_knowledge_days'] ?? 365),
            extractionCacheDays: (int) ($config['extraction_cache_days'] ?? 30),
            purgeBatchSize: (int) ($config['purge_batch_size'] ?? 500),
        );
    }

    public function emailMessagesThreshold(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d days', $this->emailMessagesDays));
    }

    public function emailEventsThreshold(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d days', $this->emailEventsDays));
    }

    public function documentsThreshold(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d days', $this->documentsGraceDays));
    }

    public function ignoredDiscoveriesThreshold(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d days', $this->ignoredDiscoveriesDays));
    }

    public function resolvedAlertsThreshold(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d days', $this->resolvedAlertsDays));
    }

    public function auditLogThreshold(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d days', $this->auditLogDays));
    }

    public function aiUsageThreshold(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d days', $this->aiUsageDays));
    }

    public function mailboxKnowledgeThreshold(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d days', $this->mailboxKnowledgeDays));
    }

    public function extractionCacheThreshold(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d days', $this->extractionCacheDays));
    }
}
