<?php

declare(strict_types=1);

namespace App\Tests\Support\Processing;

use App\Ai\Application\AiBudgetChangeHandlerInterface;
use App\Ai\Application\AiBudgetGuard;
use App\Ai\Domain\Entity\AiUsage;
use App\Ai\Domain\Enum\AiBudgetMode;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Ai\Domain\Repository\AiUsageRepositoryInterface;
use App\Mailbox\Application\CredentialCipherInterface;
use App\Mailbox\Application\Imap\ImapMessageBody;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Processing\Application\Message\ProcessEmailMessageHandler;
use App\Processing\Application\Message\ProcessEmailMessageMessage;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Imap\RecordingImapClient;

use function array_map;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Escenario compartido: un correo que necesita IA se queda sin presupuesto.
 *
 * ## Por qué un escenario y no código repetido
 *
 * Que un correo quede aplazado depende de **cuatro** cosas a la vez: que la
 * vía determinista no lo resuelva, que el buzón esté configurado, que el
 * presupuesto esté agotado y que el pipeline corra contra la base de datos
 * real. Si cada prueba montara el escenario por su cuenta, un cambio en
 * cualquiera de las cuatro piezas obligaría a tocar todas las pruebas, y el
 * olvido de una de ellas se parecería a un fallo del sistema.
 *
 * ## El correo del escenario
 *
 * Trae importe pero **no** periodicidad, así que la vía determinista no lo
 * puede resolver y tiene que escalar a la IA. Es justo el caso donde el
 * presupuesto decide: con presupuesto se resuelve, sin presupuesto se aplaza.
 */
trait DeferralScenario
{
    protected Uuid $organizationId;

    /**
     * Un buzón de IMAP configurado y activo.
     */
    protected function createAccount(): EmailAccount
    {
        $accounts = self::getContainer()->get(EmailAccountRepositoryInterface::class);

        $account = new EmailAccount($this->organizationId, 'facturas@miempresa.com');
        $account->configureImap('imap.miempresa.com', ImapEncryption::SSL, 993, null);
        $account->setCredentialsEncrypted(
            self::getContainer()->get(CredentialCipherInterface::class)->encrypt('secreto-de-prueba'),
        );
        $account->markActive();
        $accounts->save($account);

        return $account;
    }

    /**
     * Un correo que parece una factura y que la vía determinista no resuelve.
     */
    protected function createInvoicelikeMessage(EmailAccount $account): EmailMessage
    {
        $messages = self::getContainer()->get(EmailMessageRepositoryInterface::class);

        $message = new EmailMessage($this->organizationId, $account->getId(), 'INBOX', 1);
        $message->applyMetadata(
            messageId: '<factura-1@acme.example>',
            fromAddress: 'facturacion@acme.example',
            fromName: 'Acme Cloud',
            replyTo: null,
            senderDomain: 'acme.example',
            toAddresses: ['facturas@miempresa.com'],
            subject: 'Factura de Acme Cloud',
            receivedAt: new DateTimeImmutable('2026-10-03 09:00:00'),
            sizeBytes: 40_000,
            contentType: 'multipart/mixed',
            attachmentNames: ['factura-2026-10.pdf'],
            attachmentTypes: ['application/pdf'],
        );

        $messages->save($message);

        return $message;
    }

    /**
     * El buzón devuelve el cuerpo del correo y la factura en PDF.
     */
    protected function stubImapWithInvoice(): void
    {
        $client = self::getContainer()->get(RecordingImapClient::class);

        self::assertInstanceOf(RecordingImapClient::class, $client);

        $attachments = [[
            'name' => 'factura-2026-10.pdf',
            'type' => 'application/pdf',
            'contents' => PdfFixture::withLines([
                'Factura ACME-2026-10-0001',
                'Fecha 2026-10-03',
                'Total 29,90 EUR',
            ]),
        ]];

        $client->willReturnBody(new ImapMessageBody(
            uid: 1,
            textBody: 'Adjuntamos la factura.',
            htmlBody: '',
            attachmentNames: array_map(static fn (array $a): string => $a['name'], $attachments),
            attachmentTypes: array_map(static fn (array $a): string => $a['type'], $attachments),
            attachmentContents: array_map(static fn (array $a): string => $a['contents'], $attachments),
        ));
    }

    /**
     * Deja el presupuesto sin margen: tope de 1,00 € y un gasto de 5,00 € ya
     * registrado en el libro mayor.
     */
    protected function exhaustTheBudget(): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $guard = self::getContainer()->get(AiBudgetGuard::class);
        $usages = self::getContainer()->get(AiUsageRepositoryInterface::class);
        $organizationId = $this->organizationId;

        $tenantContext->runAs($organizationId, static function () use ($guard, $usages, $organizationId): void {
            $guard->configure(100, AiBudgetMode::HARD);

            $usages->save(new AiUsage(
                organizationId: $organizationId,
                providerKey: 'groq',
                purpose: AiPurpose::EXTRACTION,
                tier: AiTier::CHEAP,
                costMinor: 500,
                createdAt: new DateTimeImmutable(),
            ));
        });
    }

    /**
     * Sube el tope lo bastante para que vuelva a haber presupuesto.
     *
     * @return int cuántos correos han vuelto a la cola
     */
    protected function raiseTheBudget(): int
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $guard = self::getContainer()->get(AiBudgetGuard::class);
        $handler = self::getContainer()->get(AiBudgetChangeHandlerInterface::class);

        return $tenantContext->runAs(
            $this->organizationId,
            static function () use ($guard, $handler): int {
                $guard->configure(10000, AiBudgetMode::HARD);

                return $handler->onBudgetRaised();
            },
        );
    }

    /**
     * Cambia el tope **sin** soltar lo que está en espera.
     *
     * Es lo que hace el botón del panel: reintentar a mano. Sirve para
     *Reproducir el caso real de un correo aplazado en un mes que ya tiene
     * presupuesto nuevo.
     */
    protected function configureBudgetLimit(int $limitMinor, AiBudgetMode $mode = AiBudgetMode::HARD): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $guard = self::getContainer()->get(AiBudgetGuard::class);

        $tenantContext->runAs(
            $this->organizationId,
            static function () use ($guard, $limitMinor, $mode): void {
                $guard->configure($limitMinor, $mode);
            },
        );
    }

    /**
     * Ejecuta el pipeline sobre el mensaje.
     */
    protected function runPipeline(EmailMessage $message): void
    {
        $handler = self::getContainer()->get(ProcessEmailMessageHandler::class);

        $handler(new ProcessEmailMessageMessage($message->getId()));
    }

    /**
     * Recarga el mensaje de la base de datos.
     */
    protected function reload(EmailMessage $message): EmailMessage
    {
        $found = self::getContainer()->get(EmailMessageRepositoryInterface::class)->find($message->getId());

        self::assertNotNull($found);

        return $found;
    }
}
