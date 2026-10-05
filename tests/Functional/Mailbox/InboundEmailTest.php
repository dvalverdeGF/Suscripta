<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mailbox;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Mailbox\Application\Forwarding\EnableEmailForwarding;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Enum\EmailMessageSource;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Processing\Application\Message\ProcessEmailMessageHandler;
use App\Processing\Application\Message\ProcessEmailMessageMessage;
use App\Shared\Domain\Entity\AuditLog;
use App\Shared\Domain\Enum\AuditAction;
use Doctrine\ORM\EntityManagerInterface;

use function json_encode;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Ingesta de correo reenviado (D-21).
 *
 * Es el único endpoint público que escribe en el sistema, así que lo que se
 * comprueba aquí es sobre todo lo que **no** dice: un rechazo nunca revela si
 * la dirección existe o si el remitente está autorizado. El motivo real solo
 * queda en la auditoría.
 */
final class InboundEmailTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;
    private EmailAccount $account;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');

        $accounts = $this->accounts();

        $account = new EmailAccount($this->organizationId(), 'facturas@miempresa.com');
        $account->configureImap('imap.miempresa.com', ImapEncryption::SSL, 993, null);
        $account->setCredentialsEncrypted('cifrado');
        $account->markActive();
        $accounts->save($account);

        $this->account = $account;
    }

    private function organizationId(): \Symfony\Component\Uid\Uuid
    {
        $organizations = self::getContainer()->get(\App\Identity\Domain\Repository\OrganizationRepositoryInterface::class);
        $memberships = $organizations->findForUser($this->user);

        self::assertNotEmpty($memberships);

        return $memberships[0]['organization']->getId();
    }

    private function accounts(): EmailAccountRepositoryInterface
    {
        return self::getContainer()->get(EmailAccountRepositoryInterface::class);
    }

    private function messages(): EmailMessageRepositoryInterface
    {
        return self::getContainer()->get(EmailMessageRepositoryInterface::class);
    }

    /**
     * @param list<string> $senders
     */
    private function enableForwarding(array $senders = ['yo@miempresa.com']): string
    {
        $enable = self::getContainer()->get(EnableEmailForwarding::class);
        $account = $enable($this->account, $senders);

        $address = $account->getForwardingAddress();

        self::assertNotNull($address);

        return $address;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function post(array $overrides = []): void
    {
        $payload = $overrides + [
            'to' => $this->account->getForwardingAddress(),
            'from' => 'yo@miempresa.com',
            'subject' => 'Factura 2026-10 de OVH',
            'messageId' => '<factura-1@ovh.com>',
            'text' => 'Factura de 29,90 EUR con vencimiento el 03/10/2026.',
            'attachments' => [['name' => 'factura-2026-10.pdf', 'type' => 'application/pdf']],
        ];

        $this->client->request(
            'POST',
            '/mail/inbound',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($payload),
        );
    }

    /**
     * @return list<AuditLog>
     */
    private function auditEntries(AuditAction $action): array
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);

        $query = $manager->createQuery(
            'SELECT log FROM '.AuditLog::class.' log WHERE log.action = :action ORDER BY log.createdAt ASC',
        );
        $query->setParameter('action', $action);

        /** @var list<AuditLog> $rows */
        $rows = $query->getResult();

        return $rows;
    }

    public function testAnAuthorizedForwardIsAcceptedAndStored(): void
    {
        $this->enableForwarding();

        $this->post();

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"status":"accepted"}', (string) $this->client->getResponse()->getContent());

        $stored = $this->messages()->findPendingForAccount($this->account->getId());

        self::assertCount(1, $stored);

        $message = $stored[0];

        self::assertSame(EmailMessageSource::FORWARDING, $message->getSource());
        self::assertNull($message->getUid(), 'Un correo reenviado no tiene UID de IMAP.');
        self::assertSame('FORWARDING', $message->getFolder());
        self::assertSame('yo@miempresa.com', $message->getFromAddress());
        self::assertSame('miempresa.com', $message->getSenderDomain());
        self::assertSame('<factura-1@ovh.com>', $message->getMessageId());
        self::assertSame($this->account->getOrganizationId()->toRfc4122(), $message->getOrganizationId()->toRfc4122());
    }

    public function testTheMessageIsQueuedInsteadOfProcessedInsideTheRequest(): void
    {
        $this->enableForwarding();

        $this->post();

        $stored = $this->messages()->findPendingForAccount($this->account->getId());

        self::assertCount(1, $stored);
        self::assertSame(
            MessageProcessingState::RECEIVED,
            $stored[0]->getProcessingState(),
            'El pipeline no puede correr dentro de la petición del proveedor de correo: se encola.',
        );
    }

    /**
     * El cuerpo no se guarda: viaja con el mensaje de la cola y el pipeline
     * solo conserva un extracto (D-10).
     */
    public function testTheBodyTravelsWithTheQueuedMessageAndOnlyAnExcerptIsKept(): void
    {
        $this->enableForwarding();

        $this->post();

        $queued = $this->queuedMessages();

        self::assertCount(1, $queued);

        $message = $queued[0]->getMessage();

        self::assertInstanceOf(ProcessEmailMessageMessage::class, $message);
        self::assertNotNull($message->body);
        self::assertStringContainsString('29,90 EUR', $message->body);

        $stored = $this->messages()->findPendingForAccount($this->account->getId());

        self::assertCount(1, $stored);
        self::assertNull($stored[0]->getBodyExcerpt(), 'Todavía no se ha procesado, así que no hay extracto.');

        // Al procesarlo, el extracto queda guardado y el cuerpo se descarta.
        self::getContainer()->get(ProcessEmailMessageHandler::class)($message);

        $processed = $this->messages()->find($stored[0]->getId());

        self::assertNotNull($processed);

        $excerpt = $processed->getBodyExcerpt();

        self::assertNotNull($excerpt);
        self::assertStringContainsString('29,90 EUR', $excerpt);
        self::assertLessThanOrEqual(500, mb_strlen($excerpt));
    }

    /**
     * @return list<\Symfony\Component\Messenger\Envelope>
     */
    private function queuedMessages(): array
    {
        $transport = self::getContainer()->get('messenger.transport.mail_processing');

        self::assertInstanceOf(TransportInterface::class, $transport);

        return array_values(iterator_to_array($transport->get()));
    }

    public function testTheIngestIsAudited(): void
    {
        $this->enableForwarding();

        $this->post();

        self::assertCount(1, $this->auditEntries(AuditAction::EMAIL_FORWARDING_RECEIVED));
    }

    public function testTheSameMessageTwiceIsNotStoredTwice(): void
    {
        $this->enableForwarding();

        $this->post();
        self::assertResponseStatusCodeSame(200);

        $this->post();

        // Un duplicado no es un error: el proveedor puede reintentar y no
        // queremos que lo haga indefinidamente.
        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString('{"status":"duplicate"}', (string) $this->client->getResponse()->getContent());

        self::assertCount(1, $this->messages()->findPendingForAccount($this->account->getId()));
    }

    public function testAnUnknownRecipientIsRejectedWithoutRevealingAnything(): void
    {
        $this->enableForwarding();

        $this->post(['to' => 'inbox-00000000000000000000000000000000@inbound.suscripta.local']);

        self::assertResponseStatusCodeSame(202);
        self::assertJsonStringEqualsJsonString('{"status":"unknown_recipient"}', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $this->messages()->findPendingForAccount($this->account->getId()));
    }

    public function testAnUnauthorizedSenderIsRejectedAndAudited(): void
    {
        $this->enableForwarding(['yo@miempresa.com']);

        $this->post(['from' => 'atacante@otro-sitio.com']);

        self::assertResponseStatusCodeSame(202);
        self::assertJsonStringEqualsJsonString('{"status":"unauthorized_sender"}', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $this->messages()->findPendingForAccount($this->account->getId()), 'Un remitente no autorizado no llega a guardarse.');

        $audit = $this->auditEntries(AuditAction::EMAIL_FORWARDING_REJECTED);

        self::assertCount(1, $audit);
        self::assertSame('unauthorized_sender', $audit[0]->getMetadata()['reason'] ?? null);
    }

    public function testALookalikeDomainIsNotAuthorized(): void
    {
        $this->enableForwarding(['@miempresa.com']);

        $this->post(['from' => 'atacante@falso-miempresa.com']);

        self::assertResponseStatusCodeSame(202);
        self::assertJsonStringEqualsJsonString('{"status":"unauthorized_sender"}', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $this->messages()->findPendingForAccount($this->account->getId()));
    }

    public function testAnOversizedMessageIsRejected(): void
    {
        $this->enableForwarding();

        $this->post(['size' => 26 * 1024 * 1024]);

        self::assertResponseStatusCodeSame(202);
        self::assertJsonStringEqualsJsonString('{"status":"too_large"}', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $this->messages()->findPendingForAccount($this->account->getId()));
    }

    public function testForwardingThatIsNotEnabledIsRejected(): void
    {
        // Sin activar el reenvío no hay dirección, así que se usa una inventada.
        $this->post(['to' => 'inbox-11111111111111111111111111111111@inbound.suscripta.local']);

        self::assertResponseStatusCodeSame(202);
        self::assertJsonStringEqualsJsonString('{"status":"unknown_recipient"}', (string) $this->client->getResponse()->getContent());
    }

    public function testADisabledAddressStopsAcceptingMail(): void
    {
        $address = $this->enableForwarding();

        $disable = self::getContainer()->get(\App\Mailbox\Application\Forwarding\DisableEmailForwarding::class);
        $disable($this->account);

        $this->post(['to' => $address]);

        self::assertResponseStatusCodeSame(202);
        self::assertJsonStringEqualsJsonString('{"status":"unknown_recipient"}', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $this->messages()->findPendingForAccount($this->account->getId()));
    }

    public function testAMalformedPayloadIsRejected(): void
    {
        $this->client->request('POST', '/mail/inbound', [], [], ['CONTENT_TYPE' => 'application/json'], '{no es json');

        self::assertResponseStatusCodeSame(400);
        self::assertJsonStringEqualsJsonString('{"status":"invalid_payload"}', (string) $this->client->getResponse()->getContent());
    }

    public function testAPayloadWithoutRecipientIsRejected(): void
    {
        $this->client->request(
            'POST',
            '/mail/inbound',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['from' => 'yo@miempresa.com']),
        );

        self::assertResponseStatusCodeSame(400);
        self::assertJsonStringEqualsJsonString('{"status":"invalid_payload"}', (string) $this->client->getResponse()->getContent());
    }

    public function testTheEndpointIsPublic(): void
    {
        // No hay sesión iniciada: el proveedor de correo no puede autenticarse.
        $this->enableForwarding();

        $this->post();

        self::assertResponseStatusCodeSame(200);
    }

    public function testTheEndpointOnlyAcceptsPost(): void
    {
        $this->client->request('GET', '/mail/inbound');

        self::assertResponseStatusCodeSame(405);
    }
}
