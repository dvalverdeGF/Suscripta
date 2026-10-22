<?php

declare(strict_types=1);

namespace App\Tests\Functional\Processing;

use App\Catalog\Domain\Entity\Provider;
use App\Catalog\Domain\Repository\ProviderRepositoryInterface;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Processing\Domain\Enum\MailboxKnowledgeSource;
use App\Processing\Domain\Enum\MailboxKnowledgeType;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * La pantalla de conocimiento del buzón (ARCHITECTURE.md §13.12).
 *
 * Lo que se comprueba aquí es que el usuario **manda**: puede escribir una
 * regla, corregirla, ignorarla, borrarla y promoverla. Y que nada de eso
 * se cuela en otro buzón: una regla escrita a mano para un buzón no puede
 * tocar el correo de otro.
 */
final class MailboxKnowledgeTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;
    private Uuid $organizationId;
    private EmailAccount $account;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $this->client->loginUser($this->user);

        $memberships = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($this->user);

        self::assertNotEmpty($memberships);

        $this->organizationId = $memberships[0]['organization']->getId();
        $this->account = $this->createAccount();
    }

    public function testTheScreenOpensAndExplainsWhatItDoes(): void
    {
        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Lo que sabemos de este buzón');
        self::assertSelectorTextContains('.bo-metric__value', '0');
    }

    public function testTheMailboxScreenLinksToTheKnowledge(): void
    {
        $this->client->request('GET', '/mail/accounts/'.$this->account->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('a[href$="/knowledge"]', 'Revisar el conocimiento aprendido');
    }

    public function testTheUserCanWriteARuleByHand(): void
    {
        $this->client->request('GET', $this->url());

        $this->client->request('POST', $this->url().'/add', [
            '_token' => $this->addToken(),
            'type' => MailboxKnowledgeType::SENDER_MAPPING->value,
            'value' => 'Facturacion@OVH.com',
        ]);

        self::assertResponseRedirects($this->url());

        $entries = $this->entries()->findForAccount($this->account->getId());

        self::assertCount(1, $entries);
        self::assertSame('facturacion@ovh.com', $entries[0]->getValue());
        self::assertSame(MailboxKnowledgeSource::USER, $entries[0]->getSource());
        self::assertSame(100, $entries[0]->getConfidence());
    }

    public function testTheRuleIsShownOnTheScreen(): void
    {
        $this->writeRule('facturacion@ovh.com');

        $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('td', 'facturacion@ovh.com');
        self::assertSelectorTextContains('.bo-metric__value', '1');
    }

    public function testTheUserCanDeleteARule(): void
    {
        $entry = $this->writeRule('facturacion@ovh.com');

        $this->client->request('GET', $this->url());

        $this->client->request('POST', $this->url().'/'.$entry->getId()->toRfc4122().'/delete', [
            '_token' => $this->deleteToken($entry),
        ]);

        self::assertResponseRedirects($this->url());
        self::assertCount(0, $this->entries()->findForAccount($this->account->getId()));
    }

    public function testTheUserCanIgnoreASender(): void
    {
        $entry = $this->writeRule('facturacion@ovh.com');

        $this->client->request('GET', $this->url());

        $this->client->request('POST', $this->url().'/'.$entry->getId()->toRfc4122().'/ignore', [
            '_token' => $this->ignoreToken($entry),
        ]);

        self::assertResponseRedirects($this->url());

        $ignored = $this->entries()->findByValue(
            $this->account->getId(),
            MailboxKnowledgeType::IGNORED_SENDER,
            'facturacion@ovh.com',
        );

        self::assertNotNull($ignored);
        self::assertTrue($ignored->excludes());
    }

    public function testTheUserCanPromoteARuleToTheGlobalCatalogue(): void
    {
        $provider = new Provider('OVHcloud', 'ovhcloud');
        self::getContainer()->get(ProviderRepositoryInterface::class)->save($provider);

        $entry = new MailboxKnowledgeEntry(
            emailAccountId: $this->account->getId(),
            organizationId: $this->organizationId,
            type: MailboxKnowledgeType::SENDER_MAPPING,
            value: 'facturacion@ovh.com',
            providerId: $provider->getId(),
            source: MailboxKnowledgeSource::OBSERVED,
            confidence: 80,
        );
        $this->entries()->save($entry);

        $this->client->request('GET', $this->url());

        $this->client->request('POST', $this->url().'/'.$entry->getId()->toRfc4122().'/promote', [
            '_token' => $this->promoteToken($entry),
        ]);

        self::assertResponseRedirects($this->url());

        $reloaded = $this->entries()->find($entry->getId());

        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isPromoted());
    }

    public function testARuleOfAnotherMailboxCannotBeTouchedFromThisScreen(): void
    {
        $other = $this->createAccount('otro@miempresa.com');

        $entry = new MailboxKnowledgeEntry(
            emailAccountId: $other->getId(),
            organizationId: $this->organizationId,
            type: MailboxKnowledgeType::SENDER_MAPPING,
            value: 'facturacion@ovh.com',
            source: MailboxKnowledgeSource::USER,
        );
        $this->entries()->save($entry);

        $this->client->request('GET', $this->url());

        $this->client->request('POST', $this->url().'/'.$entry->getId()->toRfc4122().'/delete', [
            '_token' => 'cualquier-cosa',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->entries()->find($entry->getId()));
    }

    public function testAWriteWithoutAValidTokenIsRefused(): void
    {
        $this->client->request('POST', $this->url().'/add', [
            '_token' => 'falso',
            'type' => MailboxKnowledgeType::SENDER_MAPPING->value,
            'value' => 'facturacion@ovh.com',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->entries()->findForAccount($this->account->getId()));
    }

    public function testAnUnknownProviderIsNotAccepted(): void
    {
        $this->client->request('GET', $this->url());

        $this->client->request('POST', $this->url().'/add', [
            '_token' => $this->addToken(),
            'type' => MailboxKnowledgeType::SENDER_MAPPING->value,
            'value' => 'facturacion@ovh.com',
            'providerId' => 'no-es-un-uuid',
        ]);

        self::assertResponseRedirects($this->url());

        $entries = $this->entries()->findForAccount($this->account->getId());

        self::assertCount(1, $entries);
        self::assertNull($entries[0]->getProviderId());
    }

    private function writeRule(string $value): MailboxKnowledgeEntry
    {
        $entry = new MailboxKnowledgeEntry(
            emailAccountId: $this->account->getId(),
            organizationId: $this->organizationId,
            type: MailboxKnowledgeType::SENDER_MAPPING,
            value: $value,
            source: MailboxKnowledgeSource::OBSERVED,
            confidence: 80,
        );
        $this->entries()->save($entry);

        return $entry;
    }

    private function createAccount(string $address = 'facturas@miempresa.com'): EmailAccount
    {
        $accounts = self::getContainer()->get(EmailAccountRepositoryInterface::class);

        $account = new EmailAccount($this->organizationId, $address);
        $account->configureImap('imap.miempresa.com', ImapEncryption::SSL, 993, null);
        $account->markActive();
        $accounts->save($account);

        return $account;
    }

    private function entries(): MailboxKnowledgeRepositoryInterface
    {
        return self::getContainer()->get(MailboxKnowledgeRepositoryInterface::class);
    }

    private function url(): string
    {
        return '/mail/accounts/'.$this->account->getId()->toRfc4122().'/knowledge';
    }

    private function addToken(): string
    {
        $token = $this->client->getCrawler()
            ->filter('form[action="'.$this->url().'/add"] input[name="_token"]')
            ->attr('value');

        self::assertIsString($token, 'No se ha encontrado el token CSRF del formulario de alta.');

        return $token;
    }

    private function deleteToken(MailboxKnowledgeEntry $entry): string
    {
        return $this->tokenOf('mail_knowledge_delete_'.$entry->getId()->toRfc4122(), 'delete');
    }

    private function ignoreToken(MailboxKnowledgeEntry $entry): string
    {
        return $this->tokenOf('mail_knowledge_ignore_'.$entry->getId()->toRfc4122(), 'ignore');
    }

    private function promoteToken(MailboxKnowledgeEntry $entry): string
    {
        return $this->tokenOf('mail_knowledge_promote_'.$entry->getId()->toRfc4122(), 'promote');
    }

    /**
     * El token se busca en el formulario concreto de la acción, no en el
     * primero de la página: cada acción tiene el suyo.
     */
    private function tokenOf(string $tokenId, string $action): string
    {
        $token = $this->client->getCrawler()
            ->filter('form[action$="/'.$action.'"] input[name="_token"]')
            ->attr('value');

        self::assertIsString($token, sprintf('No se ha encontrado el token CSRF de la acción %s.', $action));

        return $token;
    }
}
