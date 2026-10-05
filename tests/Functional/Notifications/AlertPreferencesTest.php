<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notifications;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Enum\NotificationChannel;
use App\Notifications\Domain\Repository\NotificationPreferenceRepositoryInterface;
use App\Notifications\Domain\Service\AlertRules;
use App\Notifications\UI\Form\NotificationPreferenceFormType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La página de preferencias es la promesa de que el usuario manda sobre lo que
 * le interrumpe. Se prueba por HTTP porque lo que importa es que la matriz se
 * pinte completa y que lo guardado sea exactamente lo que se ve.
 */
final class AlertPreferencesTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');
        $this->client->loginUser($this->user);
    }

    public function testItRendersTheWholeMatrix(): void
    {
        $this->client->request('GET', '/alerts/preferences');

        self::assertResponseIsSuccessful();

        foreach (AlertType::cases() as $type) {
            self::assertSelectorExists('input[name="notification_preference_form['.NotificationPreferenceFormType::fieldName($type, NotificationChannel::IN_APP).']"]');
            self::assertSelectorExists('input[name="notification_preference_form['.NotificationPreferenceFormType::fieldName($type, NotificationChannel::EMAIL).']"]');
        }
    }

    public function testInAppIsCheckedByDefaultAndEmailIsNot(): void
    {
        $this->client->request('GET', '/alerts/preferences');

        $crawler = $this->client->getCrawler();

        $inApp = $crawler->filter('input[name="notification_preference_form['.NotificationPreferenceFormType::fieldName(AlertType::UPCOMING_CHARGE, NotificationChannel::IN_APP).']"]');
        $email = $crawler->filter('input[name="notification_preference_form['.NotificationPreferenceFormType::fieldName(AlertType::UPCOMING_CHARGE, NotificationChannel::EMAIL).']"]');

        self::assertTrue($inApp->attr('checked') !== null);
        self::assertNull($email->attr('checked'));
    }

    public function testItExplainsThatCriticalAlertsAreAlwaysEmailed(): void
    {
        $this->client->request('GET', '/alerts/preferences');

        self::assertSelectorTextContains('.app-main', 'se envían siempre por correo');
    }

    public function testOptingIntoEmailStoresTheDeviation(): void
    {
        $this->client->request('GET', '/alerts/preferences');

        $this->client->submit($this->client->getCrawler()->selectButton('Guardar preferencias')->form([
            'notification_preference_form['.NotificationPreferenceFormType::fieldName(AlertType::UPCOMING_RENEWAL, NotificationChannel::EMAIL).']' => '1',
        ]));

        self::assertResponseRedirects('/alerts/preferences');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-region', 'Preferencias guardadas');

        $stored = $this->preferences()->findOne($this->user->getId(), AlertType::UPCOMING_RENEWAL, NotificationChannel::EMAIL);

        self::assertNotNull($stored);
        self::assertTrue($stored->isEnabled());
    }

    public function testDisablingInAppStoresTheDeviation(): void
    {
        $this->client->request('GET', '/alerts/preferences');

        $this->client->submit($this->client->getCrawler()->selectButton('Guardar preferencias')->form([
            'notification_preference_form['.NotificationPreferenceFormType::fieldName(AlertType::PRICE_INCREASE, NotificationChannel::IN_APP).']' => false,
        ]));

        self::assertResponseRedirects();

        $stored = $this->preferences()->findOne($this->user->getId(), AlertType::PRICE_INCREASE, NotificationChannel::IN_APP);

        self::assertNotNull($stored);
        self::assertFalse($stored->isEnabled());
    }

    public function testGoingBackToTheDefaultDeletesTheRow(): void
    {
        $this->client->request('GET', '/alerts/preferences');
        $this->client->submit($this->client->getCrawler()->selectButton('Guardar preferencias')->form([
            'notification_preference_form['.NotificationPreferenceFormType::fieldName(AlertType::UPCOMING_RENEWAL, NotificationChannel::EMAIL).']' => '1',
        ]));

        self::assertCount(1, $this->preferences()->findForUser($this->user->getId()));

        // Volver al valor por defecto es desmarcar la casilla, no reenviar el
        // formulario tal cual: el formulario ya viene con la desviación marcada.
        $this->client->request('GET', '/alerts/preferences');
        $this->client->submit($this->client->getCrawler()->selectButton('Guardar preferencias')->form([
            'notification_preference_form['.NotificationPreferenceFormType::fieldName(AlertType::UPCOMING_RENEWAL, NotificationChannel::EMAIL).']' => false,
        ]));

        self::assertResponseRedirects();
        self::assertSame([], $this->preferences()->findForUser($this->user->getId()));
    }

    public function testSavingWithoutChangesSaysSo(): void
    {
        $this->client->request('GET', '/alerts/preferences');
        $this->client->submit($this->client->getCrawler()->selectButton('Guardar preferencias')->form());

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-region', 'ya estaban así');
    }

    public function testTheStoredDeviationIsReflectedOnTheNextVisit(): void
    {
        $this->client->request('GET', '/alerts/preferences');
        $this->client->submit($this->client->getCrawler()->selectButton('Guardar preferencias')->form([
            'notification_preference_form['.NotificationPreferenceFormType::fieldName(AlertType::UPCOMING_RENEWAL, NotificationChannel::EMAIL).']' => '1',
        ]));

        $this->client->request('GET', '/alerts/preferences');

        $email = $this->client->getCrawler()->filter('input[name="notification_preference_form['.NotificationPreferenceFormType::fieldName(AlertType::UPCOMING_RENEWAL, NotificationChannel::EMAIL).']"]');

        self::assertTrue($email->attr('checked') !== null);
    }

    public function testTheDefaultsComeFromTheRulesNotFromTheDatabase(): void
    {
        $this->client->request('GET', '/alerts/preferences');

        self::assertSame([], $this->preferences()->findForUser($this->user->getId()));
        self::assertTrue(AlertRules::defaultEnabled(AlertType::UPCOMING_CHARGE, NotificationChannel::IN_APP));
    }

    private function preferences(): NotificationPreferenceRepositoryInterface
    {
        return self::getContainer()->get(NotificationPreferenceRepositoryInterface::class);
    }
}
