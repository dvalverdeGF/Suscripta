<?php

declare(strict_types=1);

namespace App\Api\Application\ReadModel;

use function abs;

use App\Discovery\Domain\Dto\DiscoveryView;
use App\Documents\Domain\Dto\DocumentView;
use App\Mailbox\Domain\Dto\EmailAccountView;
use App\Mailbox\Domain\Entity\EmailSyncRun;
use App\Notifications\Domain\Dto\AlertView;
use App\Services\Domain\Dto\ServicePriceView;
use App\Services\Domain\Dto\ServiceView;
use App\Shared\Domain\ValueObject\Money;
use DateTimeImmutable;
use DateTimeInterface;

use function intdiv;
use function str_pad;

use const STR_PAD_LEFT;

/**
 * Convierte los read models de cada módulo en el formato de la API.
 *
 * ## Por qué un solo sitio
 *
 * Si cada endpoint tradujera sus vistas a su manera, dos endpoints que devuelven
 * el mismo recurso (por ejemplo, el servicio dentro de un listado y el
 * servicio en detalle) acabarían devolviendo campos distintos. El
 * integrador no podría fiarse de que el objeto es el mismo.
 *
 * ## Las fechas
 *
 * Siempre en ISO-8601 (`2025-03-01T00:00:00+00:00`). Nada de «01/03/2025»:
 * el formato español no se puede analizar de forma fiable en todos los
 * lenguajes.
 *
 * ## Los importes
 *
 * Se devuelven **las dos cosas**: `amount` en decimal con punto (lo que se
 * muestra) y `amount_minor` en la unidad mínima de la moneda (lo que se
 * calcula). Un integrador que suma importes debe usar el segundo: el
 * decimal es una representación para ojos humanos.
 */
final class ResourceMapper
{
    /**
     * @return array<string, mixed>
     */
    public static function service(ServiceView $service, bool $withDetails = true): array
    {
        $data = [
            'id' => $service->id->toRfc4122(),
            'name' => $service->name,
            'plan_name' => $service->planName,
            'status' => $service->status->value,
            'source' => $service->source->value,
            'currency' => $service->currency->value,
            'billing_period' => $service->billingPeriod->value,
            'billing_interval_count' => $service->billingIntervalCount,
            'provider_id' => $service->providerId?->toRfc4122(),
            'category_id' => $service->categoryId?->toRfc4122(),
            'started_at' => self::date($service->startedAt),
            'next_charge_at' => self::date($service->nextChargeAt),
            'renewal_at' => self::date($service->renewalAt),
            'commitment_end_at' => self::date($service->commitmentEndAt),
            'cancelled_at' => self::date($service->cancelledAt),
            'notice_period_days' => $service->noticePeriodDays,
            'notice_deadline' => self::date($service->noticeDeadline),
            'auto_renews' => $service->autoRenews,
            'payment_method_label' => $service->paymentMethodLabel,
            'notes' => $service->notes,
            'created_at' => self::date($service->createdAt),
            'updated_at' => self::date($service->updatedAt),
        ];

        if (null !== $service->currentAmount) {
            $data['current_price'] = self::money($service->currentAmount);
        } else {
            $data['current_price'] = null;
        }

        if (!$withDetails) {
            return $data;
        }

        $prices = [];
        foreach ($service->prices as $price) {
            $prices[] = self::price($price);
        }

        $events = [];
        foreach ($service->events as $event) {
            $events[] = [
                'id' => $event->id->toRfc4122(),
                'type' => $event->type->value,
                'occurred_at' => self::date($event->occurredAt),
                'data' => $event->data,
            ];
        }

        $data['prices'] = $prices;
        $data['events'] = $events;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function price(ServicePriceView $price): array
    {
        return [
            'id' => $price->id->toRfc4122(),
            'amount' => self::money($price->amount),
            'valid_from' => self::date($price->validFrom),
            'valid_to' => self::date($price->validTo),
            'source' => $price->source->value,
            'note' => $price->note,
            'is_current' => $price->isCurrent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function alert(AlertView $alert): array
    {
        return [
            'id' => $alert->id->toRfc4122(),
            'type' => $alert->type,
            'type_label' => $alert->typeLabel,
            'severity' => $alert->severity,
            'status' => $alert->status,
            'title' => $alert->title,
            'message' => $alert->message,
            'service_id' => $alert->serviceId?->toRfc4122(),
            'discovery_id' => $alert->discoveryId?->toRfc4122(),
            'due_at' => self::date($alert->dueAt),
            'metadata' => $alert->metadata,
            'open' => $alert->open,
            'created_at' => self::date($alert->createdAt),
            'resolved_at' => self::date($alert->resolvedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function document(DocumentView $document): array
    {
        return [
            'id' => $document->id->toRfc4122(),
            'type' => $document->type,
            'type_label' => $document->typeLabel,
            'source' => $document->source,
            'filename' => $document->originalFilename,
            'mime_type' => $document->mimeType,
            'size_bytes' => $document->sizeBytes,
            'service_id' => $document->serviceId?->toRfc4122(),
            'invoice_id' => $document->invoiceId?->toRfc4122(),
            'email_message_id' => $document->emailMessageId?->toRfc4122(),
            'checksum_sha256' => $document->checksumSha256,
            'deleted' => $document->deleted,
            'created_at' => self::date($document->createdAt),
            'deleted_at' => self::date($document->deletedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function proposal(DiscoveryView $proposal): array
    {
        return [
            'id' => $proposal->id->toRfc4122(),
            'type' => $proposal->type,
            'type_label' => $proposal->typeLabel,
            'status' => $proposal->status,
            'status_label' => $proposal->statusLabel,
            'confidence' => $proposal->confidence,
            'confidence_score' => $proposal->confidenceScore,
            'match_score' => $proposal->matchScore,
            'match_reasons' => $proposal->matchReasons,
            'proposed_data' => $proposal->proposedData,
            'matched_service_id' => $proposal->matchedServiceId?->toRfc4122(),
            'source_email_message_id' => $proposal->sourceEmailMessageId?->toRfc4122(),
            'extraction_tier' => $proposal->extractionTier,
            'ai_used' => $proposal->aiUsed,
            'inference' => $proposal->inference,
            'detected_at' => self::date($proposal->detectedAt),
            'reviewed_at' => self::date($proposal->reviewedAt),
            'resulting_service_id' => $proposal->resultingServiceId?->toRfc4122(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function mailAccount(EmailAccountView $account): array
    {
        return [
            'id' => $account->id->toRfc4122(),
            'provider' => $account->provider,
            'provider_label' => $account->providerLabel,
            'email_address' => $account->emailAddress,
            'display_name' => $account->displayName,
            'status' => $account->status,
            'status_label' => $account->statusLabel,
            'imap_host' => $account->imapHost,
            'imap_port' => $account->imapPort,
            'imap_encryption' => $account->imapEncryption,
            'imap_folder' => $account->imapFolder,
            'has_credentials' => $account->hasCredentials,
            'configured' => $account->configured,
            'forwarding_enabled' => $account->forwardingEnabled,
            'forwarding_address' => $account->forwardingAddress,
            'last_sync_at' => self::date($account->lastSyncAt),
            'last_sync_status' => $account->lastSyncStatus,
            'last_sync_error' => $account->lastSyncError,
            'created_at' => self::date($account->createdAt),
            'updated_at' => self::date($account->updatedAt),
        ];
    }

    /**
     * El resultado de una sincronización de buzón.
     *
     * @return array<string, mixed>
     */
    public static function syncRun(EmailSyncRun $run): array
    {
        return [
            'id' => $run->getId()->toRfc4122(),
            'email_account_id' => $run->getEmailAccountId()->toRfc4122(),
            'status' => $run->getStatus()->value,
            'started_at' => self::date($run->getStartedAt()),
            'finished_at' => self::date($run->getFinishedAt()),
            'messages_seen' => $run->getMessagesSeen(),
            'messages_processed' => $run->getMessagesProcessed(),
            'messages_skipped' => $run->getMessagesSkipped(),
            'messages_backfilled' => $run->getMessagesBackfilled(),
            'discoveries_created' => $run->getDiscoveriesCreated(),
            'error' => $run->getError(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function money(Money $money): array
    {
        $decimals = $money->currency->decimals();
        $factor = $money->currency->minorUnitFactor();
        $absolute = abs($money->amountMinor);
        $sign = $money->amountMinor < 0 ? '-' : '';

        $amount = $sign.intdiv($absolute, $factor);

        if (0 < $decimals) {
            $amount .= '.'.str_pad((string) ($absolute % $factor), $decimals, '0', STR_PAD_LEFT);
        }

        return [
            'amount' => $amount,
            'amount_minor' => $money->amountMinor,
            'currency' => $money->currency->value,
        ];
    }

    public static function date(?DateTimeImmutable $date): ?string
    {
        return $date?->format(DateTimeInterface::ATOM);
    }
}
