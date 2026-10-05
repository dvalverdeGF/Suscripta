<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notifications;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Notifications\Domain\Entity\Alert;
use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Repository\AlertRepositoryInterface;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Un aviso dice cuánto pagas, a quién y cuándo. Es información de negocio de
 * una persona concreta: ni se ve sin sesión ni se ve desde otra organización.
 */
final class AlertAccessTest extends WebTestCase
{
    public function testAnAnonymousVisitorIsRedirectedToLogin(): void
    {
        $client = self::createClient();
        $client->request('GET', '/alerts');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testAnAnonymousVisitorCannotSeeThePreferences(): void
    {
        $client = self::createClient();
        $client->request('GET', '/alerts/preferences');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testAnAlertFromAnotherOrganizationIsNotFound(): void
    {
        $client = self::createClient();
        $client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $intruder = self::getContainer()->get(RegisterUser::class)('intruso@example.com', 'Sup3rSecret!2026', 'Intruso');
        $client->loginUser($intruder);

        // Una petición previa obliga al cliente a reiniciar el kernel, de modo
        // que la petición siguiente use un EntityManager nuevo. Sin esto, el
        // aviso seguiría en el mapa de identidad del proceso de test y el
        // filtro `tenant` no llegaría a ejecutarse.
        $client->request('GET', '/alerts');
        self::assertResponseIsSuccessful();

        $alert = $this->alertOfAnotherOrganization();

        $client->request('GET', '/alerts/'.$alert->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }

    private function alertOfAnotherOrganization(): Alert
    {
        $owner = self::getContainer()->get(RegisterUser::class)('duena@example.com', 'Sup3rSecret!2026', 'Dueña');
        $organizationId = $this->organizationOf($owner);

        $alert = new Alert(
            organizationId: $organizationId,
            type: AlertType::UPCOMING_CHARGE,
            severity: AlertSeverity::WARNING,
            title: 'Cobro próximo: OVH',
            message: 'OVH te cobrará 29,90 € el 08/10/2026.',
            dedupKey: 'upcoming_charge|-|2026-10-08',
            dueAt: new DateTimeImmutable('2026-10-08'),
            createdAt: new DateTimeImmutable('2026-10-05 09:00:00'),
        );

        self::getContainer()->get(AlertRepositoryInterface::class)->save($alert);

        return $alert;
    }

    private function organizationOf(User $user): Uuid
    {
        $organizations = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($user);

        self::assertNotSame([], $organizations);

        return $organizations[0]['organization']->getId();
    }
}
