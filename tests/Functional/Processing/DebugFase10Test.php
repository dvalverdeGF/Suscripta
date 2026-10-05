<?php

declare(strict_types=1);

namespace App\Tests\Functional\Processing;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Mailbox\Application\Imap\ImapMessageBody;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Processing\Application\Message\ProcessEmailMessageHandler;
use App\Processing\Application\Message\ProcessEmailMessageMessage;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Imap\RecordingImapClient;
use App\Tests\Support\Processing\PdfFixture;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DebugFase10Test extends WebTestCase
{
    public function testDebug(): void
    {
        $client = self::createClient();
        $client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $organizations = self::getContainer()->get(OrganizationRepositoryInterface::class);
        $memberships = $organizations->findForUser($user);
        $organizationId = $memberships[0]['organization']->getId();

        $accounts = self::getContainer()->get(EmailAccountRepositoryInterface::class);
        $account = new EmailAccount($organizationId, 'facturas@miempresa.com', EmailAccountProvider::IMAP);
        $account->configureImap('imap.miempresa.com', ImapEncryption::SSL, 993, 'facturas@miempresa.com');
        $account->setCredentialsEncrypted('x');
        $account->markActive();
        $accounts->save($account);

        $messages = self::getContainer()->get(EmailMessageRepositoryInterface::class);
        $message = new EmailMessage($organizationId, $account->getId(), 'INBOX', 1);
        $message->applyMetadata(
            messageId: '<factura-1@ovh.com>',
            fromAddress: 'facturacion@ovh.com',
            fromName: 'OVHcloud',
            replyTo: null,
            senderDomain: 'ovh.com',
            toAddresses: ['facturas@miempresa.com'],
            subject: 'Factura 2026-10 de OVHcloud',
            receivedAt: new DateTimeImmutable('2026-10-03 09:00:00'),
            sizeBytes: 1000,
            contentType: 'text/plain',
            attachmentNames: ['factura-2026-10.pdf'],
            attachmentTypes: ['application/pdf'],
        );
        $messages->save($message);

        $imap = self::getContainer()->get(RecordingImapClient::class);
        $imap->willReturnBody(new ImapMessageBody(
            uid: 1,
            textBody: 'Hola, adjuntamos tu factura del mes.',
            htmlBody: '',
            attachmentNames: ['factura-2026-10.pdf'],
            attachmentTypes: ['application/pdf'],
            attachmentContents: [PdfFixture::withLines([
                'Factura FRA-2026-10-0042',
                'Fecha 2026-10-03',
                'Total 29,90 EUR',
                'Facturacion mensual',
            ])],
        ));

        $handler = self::getContainer()->get(ProcessEmailMessageHandler::class);
        $handler(new ProcessEmailMessageMessage($message->getId()));

        $stored = $messages->find($message->getId());
        self::assertNotNull($stored);

        fwrite(\STDERR, "\n=== STATE: ".$stored->getProcessingState()->value."\n");
        fwrite(\STDERR, '=== SCORE: '.$stored->getBillingScore()."\n");
        fwrite(\STDERR, '=== REASONS: '.json_encode($stored->getBillingReasons())."\n");
        fwrite(\STDERR, '=== TIER: '.($stored->getExtractionTier()?->value ?? 'null')."\n");
        fwrite(\STDERR, '=== EXTRACTOR: '.($stored->getExtractorUsed() ?? 'null')."\n");
        fwrite(\STDERR, '=== ERROR: '.($stored->getLastError() ?? 'null')."\n");
        fwrite(\STDERR, '=== EXCERPT: '.($stored->getBodyExcerpt() ?? 'null')."\n");

        $em = self::getContainer()->get('doctrine')->getManager();
        $events = $em->createQuery('SELECT e FROM App\Mailbox\Domain\Entity\MessageProcessingEvent e WHERE e.emailMessageId = :id ORDER BY e.occurredAt ASC')
            ->setParameter('id', $message->getId())
            ->getResult();

        foreach ($events as $event) {
            fwrite(\STDERR, sprintf(
                "=== EVENT %s -> %s (%s) extractor=%s tier=%s data=%s\n",
                $event->getFromState()?->value ?? 'null',
                $event->getToState()->value,
                $event->getReason(),
                $event->getExtractor() ?? 'null',
                $event->getTier()?->value ?? 'null',
                json_encode($event->getData()),
            ));
        }

        self::assertTrue(true);
    }
}
