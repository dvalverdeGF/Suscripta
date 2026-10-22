<?php

declare(strict_types=1);

namespace App\Tests\Functional\Onboarding;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Mailbox\Application\CredentialCipherInterface;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Tests\Support\Identity\SwitchesPlan;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La guía de bienvenida, por HTTP (Fase 17).
 *
 * ## Lo que esta prueba fija
 *
 * 1. Quien entra por primera vez **cae en la guía**, no en un panel vacío.
 * 2. La guía dice lo que falta de verdad: si el buzón está conectado, lo dice
 *    hecho, porque el estado se calcula y no se guarda.
 * 3. La guía se puede terminar, y al terminarla el panel deja de estar
 *   interceptado.
 */
final class OnboardingTest extends WebTestCase
{
    use SwitchesPlan;

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');

        $this->client->loginUser($this->user);
    }

    public function testANewUserLandsInTheGuideInsteadOfAnEmptyDashboard(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/onboarding');
    }

    public function testTheGuideListsWhatIsMissing(): void
    {
        $this->client->request('GET', '/onboarding');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Empieza por aquí');
        self::assertSelectorTextContains('.bo-main', 'Conecta el buzón donde llegan las facturas');
        self::assertSelectorTextContains('.bo-main', 'Añade tu primer servicio');
    }

    public function testTheMailboxStepIsMarkedAsDoneWhenThereIsAMailbox(): void
    {
        $this->connectMailbox();

        $this->client->request('GET', '/onboarding');

        self::assertResponseIsSuccessful();

        $row = $this->rowFor('Conecta el buzón donde llegan las facturas');

        self::assertStringContainsString('Hecho', $row);
    }

    public function testTheGuideCanBeFinishedAndThenTheDashboardOpens(): void
    {
        $this->client->request('GET', '/onboarding');

        $this->client->submit($this->client->getCrawler()->selectButton('Hecho, llévame al panel')->form());

        self::assertResponseRedirects('/');

        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Panel');

        // La guía no vuelve a aparecer: el estado está guardado.
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    public function testTheGuideCannotBeFinishedWithoutAValidCsrfToken(): void
    {
        $this->client->request('POST', '/onboarding/finish', ['_token' => 'inventado']);

        self::assertResponseStatusCodeSame(403);

        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail('ada@example.com');

        self::assertNotNull($user);
        self::assertFalse($user->isOnboardingCompleted(), 'Sin token válido la guía no se termina.');
    }

    /**
     * Un buzón ya conectado.
     *
     * Se crea la entidad directamente (y no con el caso de uso) porque el
     * caso de uso prueba la conexión IMAP de verdad, y aquí lo que se prueba
     * es que la guía se entera.
     */
    private function connectMailbox(): void
    {
        $accounts = self::getContainer()->get(EmailAccountRepositoryInterface::class);
        $organizations = self::getContainer()->get(OrganizationRepositoryInterface::class);

        $memberships = $organizations->findForUser($this->user);

        self::assertNotEmpty($memberships);

        $account = new EmailAccount($memberships[0]['organization']->getId(), 'facturas@miempresa.com');
        $account->configureImap('imap.miempresa.com', ImapEncryption::SSL, 993, null);
        $account->setCredentialsEncrypted(
            self::getContainer()->get(CredentialCipherInterface::class)->encrypt('secreto-de-prueba'),
        );
        $account->markActive();
        $accounts->save($account);
    }

    private function rowFor(string $title): string
    {
        foreach ($this->client->getCrawler()->filter('.bo-table tbody tr') as $row) {
            $text = $row->textContent;

            if (str_contains($text, $title)) {
                return $text;
            }
        }

        self::fail(sprintf('No se ha encontrado la fila «%s».', $title));
    }
}
