<?php

declare(strict_types=1);

namespace App\Mailbox\UI\Controller;

use App\Mailbox\Application\Forwarding\ForwardedEmail;
use App\Mailbox\Application\Forwarding\ForwardingIngestStatus;
use App\Mailbox\Application\Forwarding\IngestForwardedEmail;
use DateTimeImmutable;
use Exception;

use function is_array;
use function is_int;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Punto de entrada de los correos reenviados (D-21).
 *
 * Es el único endpoint **público** que escribe en el sistema, así que responde
 * siempre con el mismo formato y no revela nada: ni si la dirección existe, ni
 * si el remitente está autorizado, ni por qué se ha rechazado. Un atacante que
 * pudiera distinguir "dirección desconocida" de "remitente no autorizado"
 * tendría un oráculo para descubrir direcciones válidas.
 *
 * El motivo real sí queda en la auditoría, que es donde sirve de algo.
 */
final readonly class InboundEmailController
{
    public function __construct(
        private IngestForwardedEmail $ingest,
    ) {
    }

    #[Route('/mail/inbound', name: 'app_mail_inbound', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->response(Response::HTTP_BAD_REQUEST, 'invalid_payload');
        }

        if (!is_array($payload)) {
            return $this->response(Response::HTTP_BAD_REQUEST, 'invalid_payload');
        }

        $to = $this->string($payload, 'to');
        $from = $this->string($payload, 'from');

        if (null === $to || null === $from) {
            return $this->response(Response::HTTP_BAD_REQUEST, 'invalid_payload');
        }

        $result = ($this->ingest)(new ForwardedEmail(
            toAddress: $to,
            fromAddress: $from,
            fromName: $this->string($payload, 'fromName'),
            subject: $this->string($payload, 'subject') ?? '',
            messageId: $this->string($payload, 'messageId'),
            receivedAt: $this->date($this->string($payload, 'date')),
            textBody: $this->string($payload, 'text') ?? '',
            htmlBody: $this->string($payload, 'html') ?? '',
            toAddresses: $this->strings($payload, 'toAddresses'),
            attachmentNames: $this->attachmentField($payload, 'name'),
            attachmentTypes: $this->attachmentField($payload, 'type'),
            sizeBytes: $this->int($payload, 'size'),
        ));

        // Un duplicado no es un error: el proveedor puede reintentar. Se
        // responde 200 para que no vuelva a intentarlo.
        $status = $result->status->isAccepted() || ForwardingIngestStatus::DUPLICATE === $result->status
            ? Response::HTTP_OK
            : Response::HTTP_ACCEPTED;

        return $this->response($status, $result->status->value);
    }

    private function response(int $status, string $outcome): JsonResponse
    {
        return new JsonResponse(['status' => $outcome], $status);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function string(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && '' !== trim($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function int(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        return is_int($value) && $value > 0 ? $value : 0;
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return list<string>
     */
    private function strings(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;

        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (is_string($item) && '' !== trim($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return list<string>
     */
    private function attachmentField(array $payload, string $field): array
    {
        $attachments = $payload['attachments'] ?? null;

        if (!is_array($attachments)) {
            return [];
        }

        $result = [];

        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $value = $attachment[$field] ?? null;

            if (is_string($value) && '' !== trim($value)) {
                $result[] = $value;
            }
        }

        return $result;
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
