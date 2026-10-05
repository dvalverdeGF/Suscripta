<?php

declare(strict_types=1);

namespace App\Tests\Functional\Processing;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Shared\Application\TenantContext;
use DateTimeImmutable;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * El nivel 2 es una decisión de coste, y una decisión de coste que no se mide se
 * degrada sola: si el porcentaje de descartes baja, el pipeline analiza más de
 * lo que debería y la factura de IA sube sin que nadie lo note (D-36).
 *
 * Se prueba contra la base de datos real porque lo que se verifica es el
 * contexto de tenant: cada organización debe ver solo sus propios correos.
 */
final class MailboxStatsTest extends KernelTestCase
{
    private User $user;
    private Uuid $organizationId;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $this->organizationId = $this->organizationOf($this->user);
    }

    public function testItReportsTheFunnelOfThePipeline(): void
    {
        $account = $this->account();

        $this->message($account, 1, MessageProcessingState::IGNORED);
        $this->message($account, 2, MessageProcessingState::IGNORED);
        $this->message($account, 3, MessageProcessingState::IGNORED);
        $this->message($account, 4, MessageProcessingState::CANDIDATE);
        $this->message($account, 5, MessageProcessingState::DISCOVERY);
        $this->message($account, 6, MessageProcessingState::REQUIRES_REVIEW);
        $this->message($account, 7, MessageProcessingState::FAILED);

        $tester = $this->executeStatsCommand();

        self::assertSame(0, $tester->getStatusCode());

        $display = $tester->getDisplay();

        self::assertStringContainsString('Ada Lovelace', $display);
        self::assertStringContainsString('42,9 %', $display);
    }

    public function testTheDiscardRateIsShownAsAPercentage(): void
    {
        $account = $this->account();

        $this->message($account, 1, MessageProcessingState::IGNORED);
        $this->message($account, 2, MessageProcessingState::DISCOVERY);

        $display = $this->executeStatsCommand()->getDisplay();

        self::assertStringContainsString('50,0 %', $display);
    }

    /**
     * Sin correos no hay porcentaje que calcular: mostrar `0,0 %` haría pensar
     * que el filtro no descarta nada, cuando lo que pasa es que no ha visto
     * nada todavía.
     */
    public function testAnEmptyMailboxShowsNoPercentage(): void
    {
        $display = $this->executeStatsCommand()->getDisplay();

        self::assertStringContainsString('—', $display);
        self::assertStringNotContainsString('0,0 %', $display);
    }

    public function testItOnlyCountsTheRequestedOrganization(): void
    {
        $account = $this->account();
        $this->message($account, 1, MessageProcessingState::IGNORED);

        $other = self::getContainer()->get(RegisterUser::class)('grace@example.com', 'Sup3rSecret!2026', 'Grace Hopper');
        $otherOrganizationId = $this->organizationOf($other);
        $otherAccount = $this->accountFor($otherOrganizationId, 'grace@example.com');

        $this->message($otherAccount, 1, MessageProcessingState::IGNORED);
        $this->message($otherAccount, 2, MessageProcessingState::IGNORED);
        $this->message($otherAccount, 3, MessageProcessingState::IGNORED);

        $tester = $this->executeStatsCommand(['--organization' => $otherOrganizationId->toRfc4122()]);

        self::assertSame(0, $tester->getStatusCode());

        $display = $tester->getDisplay();

        self::assertStringContainsString('Grace Hopper', $display);
        self::assertStringNotContainsString('Ada Lovelace', $display);
        self::assertStringContainsString('100,0 %', $display);
    }

    public function testAnUnknownOrganizationIsReported(): void
    {
        $tester = $this->executeStatsCommand(['--organization' => Uuid::v7()->toRfc4122()]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('No hemos encontrado esa organización', $tester->getDisplay());
    }

    public function testItDoesNotChangeAnything(): void
    {
        $account = $this->account();
        $this->message($account, 1, MessageProcessingState::IGNORED);

        $this->executeStatsCommand();

        $counts = $this->tenant()->runAs(
            $this->organizationId,
            fn (): array => $this->messages()->countByState(),
        );

        self::assertSame(1, $counts[MessageProcessingState::IGNORED->value] ?? 0);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeStatsCommand(array $input = []): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        $application = new Application($kernel);
        $tester = new CommandTester($application->find('app:mail:stats'));
        $tester->execute($input);

        return $tester;
    }

    private function messages(): EmailMessageRepositoryInterface
    {
        return self::getContainer()->get(EmailMessageRepositoryInterface::class);
    }

    private function tenant(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function organizationOf(User $user): Uuid
    {
        $organizations = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($user);

        self::assertNotSame([], $organizations);

        return $organizations[0]['organization']->getId();
    }

    private function account(): EmailAccount
    {
        return $this->accountFor($this->organizationId, 'ada@example.com');
    }

    private function accountFor(Uuid $organizationId, string $address): EmailAccount
    {
        return $this->tenant()->runAs($organizationId, static function () use ($organizationId, $address): EmailAccount {
            $account = new EmailAccount($organizationId, $address);
            $account->markActive();

            self::getContainer()->get(EmailAccountRepositoryInterface::class)->save($account);

            return $account;
        });
    }

    private function message(EmailAccount $account, int $uid, MessageProcessingState $state): EmailMessage
    {
        return $this->tenant()->runAs($account->getOrganizationId(), static function () use ($account, $uid, $state): EmailMessage {
            $message = new EmailMessage($account->getOrganizationId(), $account->getId(), 'INBOX', $uid);
            $message->applyMetadata(
                messageId: sprintf('<%d@example.com>', $uid),
                fromAddress: 'facturacion@ovh.com',
                fromName: 'OVH',
                replyTo: null,
                senderDomain: 'ovh.com',
                toAddresses: [$account->getEmailAddress()],
                subject: 'Factura OVH',
                receivedAt: new DateTimeImmutable('2026-10-03 08:00:00'),
                sizeBytes: 2048,
                contentType: 'multipart/mixed',
                attachmentNames: ['factura.pdf'],
                attachmentTypes: ['application/pdf'],
            );
            $message->setProcessingState($state);

            self::getContainer()->get(EmailMessageRepositoryInterface::class)->save($message);

            return $message;
        });
    }
}
