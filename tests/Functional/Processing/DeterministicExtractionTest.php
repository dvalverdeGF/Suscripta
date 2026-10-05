<?php

declare(strict_types=1);

namespace App\Tests\Functional\Processing;

use App\Catalog\Domain\Entity\Provider;
use App\Catalog\Domain\Entity\ProviderIdentity;
use App\Catalog\Domain\Entity\ProviderParser;
use App\Catalog\Domain\Enum\ProviderIdentityType;
use App\Catalog\Domain\Repository\ProviderIdentityRepositoryInterface;
use App\Catalog\Domain\Repository\ProviderParserRepositoryInterface;
use App\Catalog\Domain\Repository\ProviderRepositoryInterface;
use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Mailbox\Application\CredentialCipherInterface;
use App\Mailbox\Application\Imap\ImapMessageBody;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Entity\MessageProcessingEvent;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Mailbox\Domain\Repository\MessageProcessingEventRepositoryInterface;
use App\Processing\Application\Message\ProcessEmailMessageHandler;
use App\Processing\Application\Message\ProcessEmailMessageMessage;
use App\Processing\Application\Provider\DeclarativeParser;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Imap\RecordingImapClient;
use App\Tests\Support\Processing\PdfFixture;

use function array_map;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sprintf;
use function sys_get_temp_dir;
use function unlink;

use DateTimeImmutable;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * Niveles 3 y 4 del pipeline (ARCHITECTURE.md §13.6 y §13.7).
 *
 * La promesa de la fase 10 es que una factura real se entienda **sin IA**: con
 * extracción determinista y, cuando el proveedor ya se conoce, con su parser.
 * Estas pruebas recorren el pipeline completo contra la base de datos real y
 * comprueban, además, que ninguna capa temprana saca datos del sistema.
 */
final class DeterministicExtractionTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;
    private Uuid $organizationId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');

        $memberships = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($this->user);

        self::assertNotEmpty($memberships);

        $this->organizationId = $memberships[0]['organization']->getId();
    }

    private function account(): EmailAccount
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
     * @param list<string> $attachmentNames
     * @param list<string> $attachmentTypes
     */
    private function message(
        EmailAccount $account,
        int $uid,
        string $subject,
        string $from,
        array $attachmentNames = [],
        array $attachmentTypes = [],
    ): EmailMessage {
        $messages = self::getContainer()->get(EmailMessageRepositoryInterface::class);

        $message = new EmailMessage($this->organizationId, $account->getId(), 'INBOX', $uid);
        $message->applyMetadata(
            messageId: sprintf('<factura-%d@ovh.com>', $uid),
            fromAddress: $from,
            fromName: 'OVHcloud',
            replyTo: null,
            senderDomain: 'ovh.com',
            toAddresses: ['facturas@miempresa.com'],
            subject: $subject,
            receivedAt: new DateTimeImmutable('2026-10-03 09:00:00'),
            sizeBytes: 40_000,
            contentType: 'multipart/mixed',
            attachmentNames: $attachmentNames,
            attachmentTypes: $attachmentTypes,
        );

        $messages->save($message);

        return $message;
    }

    /**
     * @param list<array{name: string, type: string, contents: string}> $attachments
     */
    private function imapReturns(string $body, array $attachments = []): void
    {
        $client = self::getContainer()->get(RecordingImapClient::class);

        $client->willReturnBody(new ImapMessageBody(
            uid: 1,
            textBody: $body,
            htmlBody: '',
            attachmentNames: array_map(static fn (array $a): string => $a['name'], $attachments),
            attachmentTypes: array_map(static fn (array $a): string => $a['type'], $attachments),
            attachmentContents: array_map(static fn (array $a): string => $a['contents'], $attachments),
        ));
    }

    private function runPipeline(EmailMessage $message): void
    {
        $handler = self::getContainer()->get(ProcessEmailMessageHandler::class);

        $handler(new ProcessEmailMessageMessage($message->getId()));
    }

    /**
     * @return list<Discovery>
     */
    private function discoveries(): array
    {
        $discoveries = self::getContainer()->get(DiscoveryRepositoryInterface::class);
        $tenantContext = self::getContainer()->get(TenantContext::class);

        return $tenantContext->runAs(
            $this->organizationId,
            static fn (): array => $discoveries->findForOrganization(),
        );
    }

    /**
     * @return list<MessageProcessingEvent>
     */
    private function events(EmailMessage $message): array
    {
        return self::getContainer()->get(MessageProcessingEventRepositoryInterface::class)
            ->findForMessage($message->getId());
    }

    private function reload(EmailMessage $message): EmailMessage
    {
        $found = self::getContainer()->get(EmailMessageRepositoryInterface::class)->find($message->getId());

        self::assertNotNull($found);

        return $found;
    }

    /**
     * Convierte un PDF en una imagen, que es lo que produce un escáner: texto
     * visible pero sin capa de texto.
     */
    private function scan(string $pdf): string
    {
        $directory = sys_get_temp_dir().'/suscripta-scan';

        if (!is_dir($directory)) {
            mkdir($directory, 0o770, true);
        }

        $source = $directory.'/origen.pdf';
        file_put_contents($source, $pdf);

        $process = new Process(['pdftoppm', '-r', '200', '-png', '-l', '1', $source, $directory.'/pagina']);
        $process->run();

        self::assertTrue($process->isSuccessful(), 'No hemos podido rasterizar el PDF de prueba.');

        $image = $directory.'/pagina-1.png';

        self::assertFileExists($image);

        $contents = (string) file_get_contents($image);

        unlink($source);
        unlink($image);

        return $contents;
    }

    public function testARealInvoiceIsUnderstoodWithoutAi(): void
    {
        $account = $this->account();
        $message = $this->message($account, 1, 'Factura 2026-10 de OVHcloud', 'facturacion@ovh.com', ['factura-2026-10.pdf'], ['application/pdf']);

        // El cuerpo del correo es un saludo: la factura va en el PDF.
        $this->imapReturns(
            'Hola, adjuntamos tu factura del mes.',
            [[
                'name' => 'factura-2026-10.pdf',
                'type' => 'application/pdf',
                'contents' => PdfFixture::withLines([
                    'Factura FRA-2026-10-0042',
                    'Fecha 2026-10-03',
                    'Total 29,90 EUR',
                    'Facturacion mensual',
                ]),
            ]],
        );

        $this->runPipeline($message);

        $discoveries = $this->discoveries();

        self::assertCount(1, $discoveries, 'La factura del PDF debe entenderse sin IA.');

        $proposed = $discoveries[0]->getProposedData();

        self::assertSame(2990, $proposed['amountMinor']);
        self::assertSame('EUR', $proposed['currency']);
        self::assertSame('FRA-2026-10-0042', $proposed['invoiceNumber']);
        self::assertSame('monthly', $proposed['billingPeriod']);

        $stored = $this->reload($message);

        self::assertSame(ExtractionTier::DETERMINISTIC, $stored->getExtractionTier());
        self::assertFalse($stored->isAiUsed(), 'Ninguna capa temprana puede gastar IA.');
        self::assertSame(0, $stored->getAiCostMinor());
        self::assertSame(MessageProcessingState::DISCOVERY, $stored->getProcessingState());
    }

    public function testTheSecondInvoiceFromAKnownProviderIsReadByItsParser(): void
    {
        $account = $this->account();

        $first = $this->message($account, 1, 'Factura 2026-10 de Acme Cloud', 'facturacion@acme.example', ['factura-2026-10.pdf'], ['application/pdf']);

        $this->imapReturns(
            'Adjuntamos tu factura.',
            [[
                'name' => 'factura-2026-10.pdf',
                'type' => 'application/pdf',
                'contents' => PdfFixture::withLines([
                    'Factura ACME-2026-10-0001',
                    'Fecha 2026-10-03',
                    'Total 19,90 EUR',
                    'Facturacion mensual',
                ]),
            ]],
        );

        $this->runPipeline($first);

        self::assertSame(ExtractionTier::DETERMINISTIC, $this->reload($first)->getExtractionTier());

        // El proveedor ya se conoce y tiene un parser declarativo: la segunda
        // factura no debe volver a depender de la extracción genérica.
        $this->registerParser('acme.example', 'acme');

        $second = $this->message($account, 2, 'Factura 2026-11 de Acme Cloud', 'facturacion@acme.example', ['factura-2026-11.pdf'], ['application/pdf']);

        $this->imapReturns(
            'Adjuntamos tu factura.',
            [[
                'name' => 'factura-2026-11.pdf',
                'type' => 'application/pdf',
                'contents' => PdfFixture::withLines([
                    'Factura ACME-2026-11-0002',
                    'Fecha 2026-11-03',
                    'Total 19,90 EUR',
                    'Facturacion mensual',
                ]),
            ]],
        );

        $this->runPipeline($second);

        $stored = $this->reload($second);

        self::assertSame(ExtractionTier::KNOWN_PARSER, $stored->getExtractionTier());
        self::assertSame('acme', $stored->getExtractorUsed());
        self::assertFalse($stored->isAiUsed());

        $discoveries = $this->discoveries();

        self::assertCount(2, $discoveries);

        $numbers = array_map(
            static fn (Discovery $discovery): mixed => $discovery->getProposedData()['invoiceNumber'] ?? null,
            $discoveries,
        );

        self::assertContains('ACME-2026-11-0002', $numbers);
    }

    public function testAScannedInvoiceGoesThroughOcrAndNotThroughAi(): void
    {
        $account = $this->account();
        $message = $this->message($account, 1, 'Factura 2026-10 de OVHcloud', 'facturacion@ovh.com', ['escaneo.pdf'], ['application/pdf']);

        $scanned = $this->scan(PdfFixture::withLines([
            'Factura FRA-2026-10-0042',
            'Fecha 2026-10-03',
            'Total 29,90 EUR',
            'Facturacion mensual',
        ]));

        $this->imapReturns('Hola, te reenviamos el escaneo de la factura.', [[
            'name' => 'escaneo.pdf',
            'type' => 'application/pdf',
            'contents' => $scanned,
        ]]);

        $this->runPipeline($message);

        $discoveries = $this->discoveries();

        self::assertCount(1, $discoveries, 'El OCR debe leer el escaneo sin recurrir a la IA.');

        $proposed = $discoveries[0]->getProposedData();

        self::assertSame(2990, $proposed['amountMinor']);
        self::assertSame('FRA-2026-10-0042', $proposed['invoiceNumber']);

        $stored = $this->reload($message);

        self::assertFalse($stored->isAiUsed());
        self::assertSame(ExtractionTier::DETERMINISTIC, $stored->getExtractionTier());
    }

    public function testTheEarlyLevelsDoNotSendAnythingOutsideTheSystem(): void
    {
        $account = $this->account();
        $message = $this->message($account, 1, 'Factura 2026-10 de OVHcloud', 'facturacion@ovh.com', ['factura-2026-10.pdf'], ['application/pdf']);

        $this->imapReturns(
            'Adjuntamos tu factura.',
            [[
                'name' => 'factura-2026-10.pdf',
                'type' => 'application/pdf',
                'contents' => PdfFixture::withLines([
                    'Factura FRA-2026-10-0042',
                    'Fecha 2026-10-03',
                    'Total 29,90 EUR',
                    'Facturacion mensual',
                ]),
            ]],
        );

        $this->runPipeline($message);

        $stored = $this->reload($message);

        self::assertFalse($stored->isAiUsed());
        self::assertSame(0, $stored->getAiCostMinor());

        // El registro de transiciones deja constancia de con qué se extrajo:
        // es la prueba de que el nivel 3 resolvió el mensaje por sí solo.
        $extracted = null;

        foreach ($this->events($message) as $event) {
            if (MessageProcessingState::EXTRACTED === $event->getToState()) {
                $extracted = $event;
            }
        }

        self::assertInstanceOf(MessageProcessingEvent::class, $extracted);
        self::assertSame(ExtractionTier::DETERMINISTIC, $extracted->getTier());
        self::assertNull($extracted->getAiUsageId(), 'Sin IA no puede haber consumo asociado.');
    }

    public function testAFailingParserDoesNotBreakTheBatch(): void
    {
        $account = $this->account();

        // Un parser que no reconoce el documento: el pipeline debe seguir
        // adelante con la extracción determinista en lugar de fallar.
        $this->registerParser('acme.example', 'acme', [
            'required_any' => ['formato que no existe en este documento'],
            'amount_pattern' => '/total\s+([0-9.,]+)\s*€/i',
        ]);

        $message = $this->message($account, 1, 'Factura 2026-10 de Acme Cloud', 'facturacion@acme.example', ['factura-2026-10.pdf'], ['application/pdf']);

        $this->imapReturns(
            'Adjuntamos tu factura.',
            [[
                'name' => 'factura-2026-10.pdf',
                'type' => 'application/pdf',
                'contents' => PdfFixture::withLines([
                    'Factura ACME-2026-10-0001',
                    'Fecha 2026-10-03',
                    'Total 19,90 EUR',
                    'Facturacion mensual',
                ]),
            ]],
        );

        $this->runPipeline($message);

        $stored = $this->reload($message);

        self::assertSame(MessageProcessingState::DISCOVERY, $stored->getProcessingState());
        self::assertSame(ExtractionTier::DETERMINISTIC, $stored->getExtractionTier());
        self::assertCount(1, $this->discoveries());

        $parsers = self::getContainer()->get(ProviderParserRepositoryInterface::class);
        $provider = self::getContainer()->get(ProviderRepositoryInterface::class)->findGlobalBySlug('acme');

        self::assertNotNull($provider);

        $rows = $parsers->findForProvider($provider->getId());

        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]->getFailureCount(), 'El parser que no reconoce el documento queda marcado.');
        self::assertSame(0, $rows[0]->getSuccessCount());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerParser(string $domain, string $slug, array $config = []): void
    {
        $providers = self::getContainer()->get(ProviderRepositoryInterface::class);

        $provider = $providers->findGlobalBySlug($slug);

        if (null === $provider) {
            $provider = new Provider('Acme Cloud', $slug, null, true);
            $providers->save($provider);
        }

        $identities = self::getContainer()->get(ProviderIdentityRepositoryInterface::class);

        if (null === $identities->findByTypeAndValue(ProviderIdentityType::DOMAIN, $domain)) {
            $identities->save(new ProviderIdentity($provider, ProviderIdentityType::DOMAIN, $domain));
        }

        // La clave tiene que ser la de un parser registrado en el contenedor:
        // una clave huérfana se ignora a propósito (ARCHITECTURE.md §13.7).
        $parsers = self::getContainer()->get(ProviderParserRepositoryInterface::class);

        $parsers->save(new ProviderParser($provider, DeclarativeParser::KEY, 1, $config));
    }
}
