<?php

declare(strict_types=1);

namespace App\Tests\Functional\Observability;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Entity\EmailSyncRun;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailSyncRunRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Tests\Support\FreezesClock;
use App\Tests\Support\Identity\CompletesOnboarding;
use DateTimeImmutable;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * El panel de observabilidad es la respuesta a la pregunta que hace el
 * cliente cuando algo no aparece: «¿ha entrado mi correo?».
 *
 * Lo que se comprueba aquí es lo que la pantalla promete: que el número que
 * enseña es el de **esta** organización, que el periodo manda sobre lo que se
 * cuenta y que un fallo de sincronización se ve en pantalla en lugar de
 * quedarse en el log.
 */
final class ObservabilityPanelTest extends WebTestCase
{
    use CompletesOnboarding;
    use FreezesClock;

    private KernelBrowser $client;
    private Uuid $organizationId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        // Sin reinicio del kernel, el reloj congelado sigue siendo el mismo
        // en todas las peticiones de la prueba: de lo contrario, la segunda
        // petición mediría contra el reloj real y el periodo dejaría de
        // ser reproducible.
        $this->client->disableReboot();

        $this->freezeClock('2026-10-20 09:00:00');

        $user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $this->completeOnboarding($user);
        $this->organizationId = $this->organizationOf($user);
        $this->client->loginUser($user);
    }

    public function testThePanelIsOnlyForSignedInUsers(): void
    {
        // Se vacía la cookie de sesión: la petición siguiente es anónima.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/observability');

        self::assertResponseRedirects('/login');
    }

    public function testItShowsTheActivityOfTheOrganization(): void
    {
        $account = $this->account();

        $this->message($account, 1, '2026-10-19 08:00:00', ExtractionTier::DETERMINISTIC);
        $this->message($account, 2, '2026-10-19 09:30:00', ExtractionTier::AI_CHEAP);
        $this->syncRun($account, '2026-10-19 10:00:00', 2, 2, 1);
        $this->syncRun($account, '2026-10-19 12:00:00', 1, 0, 0, failed: true);

        $this->client->request('GET', '/observability');

        self::assertResponseIsSuccessful();

        self::assertSame('2', $this->metric('Sincronizaciones'));
        self::assertSame('3', $this->metric('Correos vistos'));
        self::assertSame('1', $this->metric('Descubrimientos'));
    }

    public function testAFailedSyncRunIsCalledOut(): void
    {
        $account = $this->account();
        $this->syncRun($account, '2026-10-19 10:00:00', 1, 0, 0, failed: true);

        $this->client->request('GET', '/observability');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Hay algo que revisar', $this->text());
        self::assertStringContainsString('1 sincronización fallida', $this->text());
    }

    public function testThePeriodLimitsWhatIsCounted(): void
    {
        $account = $this->account();

        // Una pasada de hace diez días: entra en la ventana de 30 días y no
        // entra en la de 7.
        $this->syncRun($account, '2026-10-10 10:00:00', 5, 5, 0);

        $this->client->request('GET', '/observability?period=30d');
        self::assertResponseIsSuccessful();
        self::assertSame('1', $this->metric('Sincronizaciones'));

        $this->client->request('GET', '/observability?period=7d');
        self::assertResponseIsSuccessful();
        self::assertSame('0', $this->metric('Sincronizaciones'));
    }

    public function testThePeriodsAreOffered(): void
    {
        $this->client->request('GET', '/observability');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/observability?period=24h"]');
        self::assertSelectorExists('a[href="/observability?period=7d"]');
        self::assertSelectorExists('a[href="/observability?period=30d"]');
    }

    public function testItDoesNotShowWhatOtherOrganizationsDid(): void
    {
        $other = self::getContainer()->get(RegisterUser::class)('grace@example.com', 'Sup3rSecret!2026', 'Grace Hopper');
        $otherOrganizationId = $this->organizationOf($other);

        $account = $this->accountFor($otherOrganizationId, 'grace@example.com');
        $this->syncRun($account, '2026-10-19 10:00:00', 9, 9, 4);

        $this->client->request('GET', '/observability');

        self::assertResponseIsSuccessful();
        self::assertSame('0', $this->metric('Sincronizaciones'));
        self::assertSame('0', $this->metric('Correos vistos'));
    }

    /**
     * El valor de una métrica concreta del panel.
     */
    private function metric(string $label): string
    {
        $crawler = $this->client->getCrawler();
        $labels = $crawler->filter('.bo-metric__label')->each(static fn ($node): string => trim($node->text()));

        foreach ($labels as $index => $name) {
            if ($name === $label) {
                return trim($crawler->filter('.bo-metric__value')->eq($index)->text());
            }
        }

        self::fail(sprintf('No se encuentra la métrica «%s» en el panel.', $label));
    }

    private function text(): string
    {
        return $this->client->getCrawler()->text();
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

    private function message(EmailAccount $account, int $uid, string $receivedAt, ExtractionTier $tier): EmailMessage
    {
        return $this->tenant()->runAs($account->getOrganizationId(), static function () use ($account, $uid, $receivedAt, $tier): EmailMessage {
            $message = new EmailMessage($account->getOrganizationId(), $account->getId(), 'INBOX', $uid);
            $message->applyMetadata(
                messageId: sprintf('<%d@example.com>', $uid),
                fromAddress: 'facturacion@ovh.com',
                fromName: 'OVH',
                replyTo: null,
                senderDomain: 'ovh.com',
                toAddresses: [$account->getEmailAddress()],
                subject: 'Factura OVH',
                receivedAt: new DateTimeImmutable($receivedAt),
                sizeBytes: 2048,
                contentType: 'multipart/mixed',
                attachmentNames: ['factura.pdf'],
                attachmentTypes: ['application/pdf'],
            );
            $message->recordExtraction($tier, 'test', ExtractionTier::AI_CHEAP === $tier, 1200);
            $message->markProcessed(new DateTimeImmutable($receivedAt)->modify('+3 seconds'));

            self::getContainer()->get(EmailMessageRepositoryInterface::class)->save($message);

            return $message;
        });
    }

    private function syncRun(EmailAccount $account, string $startedAt, int $seen, int $processed, int $discoveries, bool $failed = false): EmailSyncRun
    {
        return $this->tenant()->runAs($account->getOrganizationId(), static function () use ($account, $startedAt, $seen, $processed, $discoveries, $failed): EmailSyncRun {
            $start = new DateTimeImmutable($startedAt);
            $run = new EmailSyncRun($account->getOrganizationId(), $account->getId(), $start);
            $run->countSeen($seen);
            $run->countProcessed($processed);
            $run->countDiscovery($discoveries);

            if ($failed) {
                $run->fail($start->modify('+10 seconds'), 'El servidor IMAP no respondió.');
            } else {
                $run->complete($start->modify('+10 seconds'));
            }

            self::getContainer()->get(EmailSyncRunRepositoryInterface::class)->save($run);

            return $run;
        });
    }

    private function tenant(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
