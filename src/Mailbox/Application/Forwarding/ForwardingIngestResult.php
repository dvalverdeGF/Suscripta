<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Forwarding;

use App\Mailbox\Domain\Entity\EmailMessage;

/**
 * Qué ha pasado con un correo reenviado.
 */
final readonly class ForwardingIngestResult
{
    public function __construct(
        public ForwardingIngestStatus $status,
        public ?EmailMessage $message = null,
    ) {
    }

    public static function accepted(EmailMessage $message): self
    {
        return new self(ForwardingIngestStatus::ACCEPTED, $message);
    }

    public static function rejected(ForwardingIngestStatus $status): self
    {
        return new self($status);
    }
}
