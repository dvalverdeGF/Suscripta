<?php

declare(strict_types=1);

namespace App\Tests\Functional\Processing;

use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Mailbox\Application\Forwarding\EnableEmailForwarding;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Processing\Application\Message\ProcessEmailMessageHandler;
use App\Processing\Application\Message\ProcessEmailMessageMessage;
use App\Shared\Application\TenantContext;

use function array_values;
use function json_encode;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Deduplicación entre cuentas (D-27).
 *
 * La misma factura puede llegar por dos buzones de la organización —uno
 * corporativo y otro personal, por ejemplo—. El usuario no debe ver dos
 * propuestas idénticas: la clave de deduplicación del `Discovery` no incluye el
 * mensaje de origen precisamente para que ambas colapsen en una.
 */
final class CrossAccountDedupTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;
    private Uuid $organizationId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');

        $organizations = self::getContainer()->get(OrganizationRepositoryInterface::class);
        $memberships = $organizations->findForUser($this->user);

        self::assertNotEmpty($memberships);

        $this->organizationId = $memberships[0]['organization']->getId();
    }

    private function account(string $address): EmailAccount
    {
        $accounts = self::getContainer()->get(EmailAccountRepositoryInterface::class);

        $account = new EmailAccount($this->organizationId, $address);
        $account->configureImap('imap.miempresa.com', ImapEncryption::SSL, 993, null);
        $account->setCredentialsEncrypted('cifrado');
        $account->markActive();
        $accounts->save($account);

        $enable = self::getContainer()->get(EnableEmailForwarding::class);
        $account = $enable($account, ['yo@miempresa.com']);

        self::assertNotNull($account->getForwardingAddress());

        return $account;
    }

    /**
     * Reenvía un correo y devuelve la petición que quedó en la cola.
     *
     * La cola en memoria se vacía entre peticiones (el kernel reinicia los
     * servicios), así que hay que recogerla justo después de cada envío.
     */
    private function forward(EmailAccount $account, string $messageId, string $subject, string $text): ProcessEmailMessageMessage
    {
        $this->client->request(
            'POST',
            '/mail/inbound',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode([
                'to' => $account->getForwardingAddress(),
                'from' => 'yo@miempresa.com',
                'subject' => $subject,
                'messageId' => $messageId,
                'text' => $text,
                'attachments' => [['name' => 'factura.pdf', 'type' => 'application/pdf']],
            ]),
        );

        self::assertResponseStatusCodeSame(200);

        $queued = $this->queued();

        self::assertCount(1, $queued, 'El correo reenviado debe quedar encolado, no procesarse en la petición.');

        $message = $queued[0]->getMessage();

        self::assertInstanceOf(ProcessEmailMessageMessage::class, $message);

        return $message;
    }

    /**
     * @return list<Envelope>
     */
    private function queued(): array
    {
        $transport = self::getContainer()->get('messenger.transport.mail_processing');

        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values($transport->getSent());
    }

    /**
     * @param list<ProcessEmailMessageMessage> $messages
     */
    private function runPipeline(array $messages): void
    {
        $handler = self::getContainer()->get(ProcessEmailMessageHandler::class);

        foreach ($messages as $message) {
            $handler($message);
        }
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

    public function testTheSameInvoiceInTwoMailboxesProducesASingleDiscovery(): void
    {
        $corporate = $this->account('facturas@miempresa.com');
        $personal = $this->account('ada@personal.com');

        self::assertNotSame($corporate->getForwardingAddress(), $personal->getForwardingAddress());

        $first = $this->forward($corporate, '<factura-1@ovh.com>', 'Factura 2026-10 de OVH', 'Factura de 29,90 EUR. Se renovará mensualmente el día 3.');
        $second = $this->forward($personal, '<factura-1-copia@ovh.com>', 'Factura 2026-10 de OVH', 'Factura de 29,90 EUR. Se renovará mensualmente el día 3.');

        self::assertNotSame($first->emailMessageId->toRfc4122(), $second->emailMessageId->toRfc4122());

        $this->runPipeline([$first, $second]);

        $discoveries = $this->discoveries();

        self::assertCount(1, $discoveries, 'La misma factura en dos buzones no puede proponerse dos veces.');

        $discovery = $discoveries[0];

        self::assertSame($this->organizationId->toRfc4122(), $discovery->getOrganizationId()->toRfc4122());

        // Las dos evidencias quedan registradas: el usuario puede ver de dónde
        // salió la propuesta, aunque solo haya una.
        $repository = self::getContainer()->get(DiscoveryRepositoryInterface::class);
        $evidence = $repository->findEvidence($discovery->getId());

        self::assertCount(2, $evidence);
    }

    public function testTwoDifferentInvoicesProduceTwoDiscoveries(): void
    {
        $corporate = $this->account('facturas@miempresa.com');

        $first = $this->forward($corporate, '<factura-1@ovh.com>', 'Factura 2026-10 de OVH', 'Factura de 29,90 EUR. Se renovará mensualmente el día 3.');
        $second = $this->forward($corporate, '<factura-2@ovh.com>', 'Factura 2026-11 de OVH', 'Factura de 34,90 EUR. Se renovará mensualmente el día 3.');

        $this->runPipeline([$first, $second]);

        self::assertCount(2, $this->discoveries(), 'Un importe distinto es una propuesta distinta.');
    }
}
