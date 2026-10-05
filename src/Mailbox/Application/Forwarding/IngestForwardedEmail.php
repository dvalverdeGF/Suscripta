<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Forwarding;

use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Enum\EmailMessageSource;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Processing\Application\Message\ProcessEmailMessageMessage;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Enum\AuditAction;

use function hash;
use function mb_strrpos;
use function mb_strtolower;
use function mb_substr;
use function preg_replace;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * Acepta un correo reenviado a la dirección de ingesta (D-21).
 *
 * Es la puerta de entrada **pública** del sistema, así que cada paso está
 * pensado como una comprobación, no como una suposición (SECURITY.md §2.4):
 *
 * 1. La dirección destinataria identifica la cuenta. Si no existe, se descarta.
 * 2. El reenvío tiene que estar activo.
 * 3. El remitente tiene que estar autorizado. Sin esto, la dirección sería un
 *    buzón abierto donde cualquiera podría meter facturas falsas.
 * 4. Se aplica un límite de tamaño y otro de frecuencia.
 * 5. Se deduplica por `messageId`, igual que la vía IMAP.
 *
 * El correo aceptado se convierte en un `EmailMessage` normal y entra en el
 * mismo pipeline: a partir de aquí el sistema no distingue por dónde llegó.
 */
final readonly class IngestForwardedEmail
{
    /**
     * Tope de tamaño. Un reenvío con adjuntos pesados no es una factura.
     */
    public const int MAX_SIZE_BYTES = 25 * 1024 * 1024;

    /**
     * Carpeta lógica de los mensajes reenviados. No existe en ningún servidor:
     * es la forma de que la clave `(cuenta, carpeta, uid)` siga teniendo sentido
     * con `uid = null`.
     */
    public const string FOLDER = 'FORWARDING';

    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
        private EmailMessageRepositoryInterface $messages,
        private MessageBusInterface $bus,
        private RateLimiterFactoryInterface $inboundEmailLimiter,
        private TenantContext $tenantContext,
        private AuditLoggerInterface $auditLogger,
        private Clock $clock,
    ) {
    }

    public function __invoke(ForwardedEmail $email): ForwardingIngestResult
    {
        $account = $this->accounts->findByForwardingAddress($email->toAddress);

        if (null === $account) {
            return ForwardingIngestResult::rejected(ForwardingIngestStatus::UNKNOWN_RECIPIENT);
        }

        if (!$account->isForwardingEnabled()) {
            return ForwardingIngestResult::rejected(ForwardingIngestStatus::FORWARDING_DISABLED);
        }

        if (!$account->isSenderAuthorized($email->fromAddress)) {
            $this->auditLogger->log(
                action: AuditAction::EMAIL_FORWARDING_REJECTED,
                targetType: 'email_account',
                targetId: $account->getId()->toRfc4122(),
                metadata: [
                    'reason' => ForwardingIngestStatus::UNAUTHORIZED_SENDER->value,
                    'from' => mb_strtolower(trim($email->fromAddress)),
                ],
            );

            return ForwardingIngestResult::rejected(ForwardingIngestStatus::UNAUTHORIZED_SENDER);
        }

        if ($email->sizeBytes > self::MAX_SIZE_BYTES) {
            return ForwardingIngestResult::rejected(ForwardingIngestStatus::TOO_LARGE);
        }

        $limiter = $this->inboundEmailLimiter->create($account->getId()->toRfc4122());

        if (!$limiter->consume()->isAccepted()) {
            return ForwardingIngestResult::rejected(ForwardingIngestStatus::RATE_LIMITED);
        }

        return $this->tenantContext->runAs(
            $account->getOrganizationId(),
            fn (): ForwardingIngestResult => $this->store($account->getId(), $account->getOrganizationId(), $email),
        );
    }

    private function store(Uuid $accountId, Uuid $organizationId, ForwardedEmail $email): ForwardingIngestResult
    {
        if (null !== $email->messageId && '' !== $email->messageId
            && null !== $this->messages->findByAccountAndMessageId($accountId, $email->messageId)) {
            return ForwardingIngestResult::rejected(ForwardingIngestStatus::DUPLICATE);
        }

        $body = $email->body();

        $message = new EmailMessage(
            organizationId: $organizationId,
            emailAccountId: $accountId,
            folder: self::FOLDER,
            uid: null,
            source: EmailMessageSource::FORWARDING,
        );

        $message->applyMetadata(
            messageId: $email->messageId,
            fromAddress: $email->fromAddress,
            fromName: $email->fromName,
            replyTo: null,
            senderDomain: $this->domainOf($email->fromAddress),
            toAddresses: [] === $email->toAddresses ? [$email->toAddress] : $email->toAddresses,
            subject: $email->subject,
            receivedAt: $email->receivedAt ?? $this->clock->now(),
            sizeBytes: $email->sizeBytes,
            contentType: '' === $email->htmlBody ? 'text/plain' : 'text/html',
            attachmentNames: $email->attachmentNames,
            attachmentTypes: $email->attachmentTypes,
        );

        $message->setContentHash(self::contentHash($body));

        $this->messages->save($message);

        $this->auditLogger->log(
            action: AuditAction::EMAIL_FORWARDING_RECEIVED,
            targetType: 'email_message',
            targetId: $message->getId()->toRfc4122(),
            metadata: ['from' => $email->fromAddress, 'subject' => mb_substr($email->subject, 0, 200)],
        );

        // El cuerpo viaja con la petición porque no hay buzón del que
        // descargarlo. Es un dato de paso por la cola, no un almacén (D-10).
        //
        // Se enruta explícitamente a `mail_processing`: sin el sello, el
        // mensaje no tiene ruta declarada y Messenger lo manejaría **en línea**,
        // dejando al proveedor de correo esperando a que termine todo el
        // pipeline dentro de su petición HTTP.
        $this->bus->dispatch(
            new ProcessEmailMessageMessage($message->getId(), $body),
            [new TransportNamesStamp(['mail_processing'])],
        );

        return ForwardingIngestResult::accepted($message);
    }

    /**
     * Mismo hash que usa el pipeline, para que un correo reenviado y el mismo
     * correo leído por IMAP compartan caché de extracción (D-37).
     */
    public static function contentHash(string $body): string
    {
        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(trim($body))) ?? '';

        return hash('sha256', $normalized);
    }

    private function domainOf(string $address): ?string
    {
        $at = mb_strrpos($address, '@');

        if (false === $at) {
            return null;
        }

        $domain = mb_strtolower(mb_substr($address, $at + 1));

        return '' === $domain ? null : $domain;
    }
}
