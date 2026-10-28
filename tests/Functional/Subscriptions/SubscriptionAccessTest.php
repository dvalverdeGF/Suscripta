<?php

declare(strict_types=1);

namespace App\Tests\Functional\Subscriptions;

use App\Identity\Application\RegisterUser;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Subscriptions\Domain\Repository\SubscriptionFilters;
use App\Subscriptions\Domain\Repository\SubscriptionRepositoryInterface;

use function sprintf;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Control de acceso y aislamiento por organización de las suscripciones.
 *
 * Clase aparte porque `WebTestCase::createClient()` solo puede llamarse una vez
 * por test: el caso anónimo y el caso autenticado no caben en el mismo.
 */
final class SubscriptionAccessTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/subscriptions');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testAnonymousUserCannotReachTheCreateForm(): void
    {
        $client = self::createClient();
        $client->request('GET', '/subscriptions/new');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testSubscriptionOfAnotherOrganizationIsNotVisible(): void
    {
        $client = self::createClient();
        $client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $register = self::getContainer()->get(RegisterUser::class);

        $ada = $register('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $client->loginUser($ada);

        $client->request('GET', '/subscriptions/new');
        $client->submitForm('Añadir suscripción', [
            'subscription_form[name]' => 'Plan Pro de Ada',
            'subscription_form[currency]' => Currency::EUR->value,
            'subscription_form[billingPeriod]' => BillingPeriod::MONTHLY->value,
            'subscription_form[billingIntervalCount]' => '1',
            'subscription_form[startedAt]' => '2026-01-15',
        ]);
        self::assertResponseRedirects();

        // Consume the flash message so it does not leak into the next user's page.
        $client->followRedirect();

        $adaSubscriptionId = $this->findByName('Plan Pro de Ada')->getId()->toRfc4122();

        // Grace Hopper pertenece a otra organización y no debe ver nada de Ada.
        $grace = $register('grace@example.com', 'Sup3rSecret!2026', 'Grace Hopper');
        $client->loginUser($grace);

        $client->request('GET', '/subscriptions');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bo-empty__title', 'Todavía no hay suscripciones');
        self::assertSelectorTextNotContains('.bo-main', 'Plan Pro de Ada');

        $client->request('GET', '/subscriptions/'.$adaSubscriptionId);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/subscriptions/'.$adaSubscriptionId.'/edit');
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/subscriptions/'.$adaSubscriptionId.'/delete', ['_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(404);

        // La suscripción de Ada sigue intacta y accesible para ella.
        $client->loginUser($ada);
        $client->request('GET', '/subscriptions/'.$adaSubscriptionId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Plan Pro de Ada');
    }

    private function findByName(string $name): \App\Subscriptions\Domain\Entity\Subscription
    {
        $repository = self::getContainer()->get(SubscriptionRepositoryInterface::class);

        foreach ($repository->findForOrganization(new SubscriptionFilters()) as $subscription) {
            if ($subscription->getName() === $name) {
                return $subscription;
            }
        }

        self::fail(sprintf('No se ha creado la suscripción «%s».', $name));
    }
}
