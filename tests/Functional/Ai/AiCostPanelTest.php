<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ai;

use App\Ai\Application\AiBudgetGuard;
use App\Ai\Domain\Entity\AiUsage;
use App\Ai\Domain\Enum\AiBudgetMode;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Ai\Domain\Repository\AiUsageRepositoryInterface;
use App\Ai\Domain\ValueObject\AiCost;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Tests\Support\Identity\SwitchesPlan;
use App\Tests\Support\Processing\DeferralScenario;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * El panel de coste de IA, por HTTP (Fase 14).
 *
 * ## Lo que esta pantalla promete
 *
 * Que el cliente vea, sin pedirle un informe a nadie, cuánto ha gastado este
 * mes, en qué se lo ha gastado y cuánta IA se ha ahorrado. Por eso la
 * pantalla no es un formulario: es un informe, y por eso enseña el mes en
 * curso sin que haya que elegir nada.
 *
 * La segunda promesa es que el presupuesto no es un número enterrado: si se
 * agota, la pantalla lo dice y ofrece la acción que lo arregla.
 */
final class AiCostPanelTest extends WebTestCase
{
    use DeferralScenario;
    use SwitchesPlan;

    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'https://localhost');

        $this->user = self::getContainer()->get(RegisterUser::class)('ada@example.com', 'Sup3rSecret!2026', 'Ada Lovelace');

        $memberships = self::getContainer()->get(OrganizationRepositoryInterface::class)->findForUser($this->user);
        self::assertNotEmpty($memberships);
        $this->organizationId = $memberships[0]['organization']->getId();

        // La IA solo existe en el plan PRO (Fase 17).
        $this->switchPlanOfFirstMembership($this->user);

        $this->client->loginUser($this->user);
    }

    public function testThePanelOpensAndExplainsThatNothingHasBeenSpentYet(): void
    {
        $this->client->request('GET', '/ai/cost');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bo-main', 'Todavía no hay uso de IA');
    }

    public function testThePanelShowsTheCostOfTheMonth(): void
    {
        $this->recordUsage(costMinor: 1250, providerKey: 'groq', purpose: AiPurpose::EXTRACTION);

        $this->client->request('GET', '/ai/cost');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bo-main', '12,50');
        self::assertSelectorTextContains('.bo-main', '1 llamada');
    }

    public function testThePanelBreaksTheCostDownByProviderAndPurpose(): void
    {
        $this->recordUsage(costMinor: 1250, providerKey: 'groq', purpose: AiPurpose::EXTRACTION);
        $this->recordUsage(costMinor: 300, providerKey: 'openai', purpose: AiPurpose::SUMMARIZATION);

        $this->client->request('GET', '/ai/cost');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bo-main', 'Por proveedor');
        self::assertSelectorTextContains('.bo-main', 'Por necesidad');
        self::assertSelectorTextContains('.bo-main', '12,50');
        self::assertSelectorTextContains('.bo-main', '3,00');
    }

    public function testThePanelShowsTheBudgetAndSaysWhenItIsExhausted(): void
    {
        $this->configureBudget(100, AiBudgetMode::HARD);
        $this->recordUsage(costMinor: 500, providerKey: 'groq', purpose: AiPurpose::EXTRACTION);

        $this->client->request('GET', '/ai/cost');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bo-main', 'Presupuesto de este mes');
        self::assertSelectorTextContains('.bo-main', 'Presupuesto agotado');
        self::assertSelectorTextContains('.bo-main', '5,00');
    }

    public function testWithoutAnOwnLimitThePanelShowsTheLimitOfThePlan(): void
    {
        // Un tope de `0` significa «sin tope propio»: lo que manda es el del
        // plan (Fase 17). El panel lo dice, para que nadie crea que ese tope
        // se lo ha puesto él.
        $this->configureBudget(0, AiBudgetMode::SOFT);

        $this->client->request('GET', '/ai/cost');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bo-main', 'es el máximo que permite tu plan');
    }

    public function testThePlanDoesNotAllowToSpendMoreThanItsCeiling(): void
    {
        // Un tope dentro del plan se guarda…
        $this->configureBudget(5000, AiBudgetMode::SOFT);

        // …pero uno por encima del techo del plan se rechaza y no cambia nada.
        $this->client->request('GET', '/ai');

        $this->client->submit($this->client->getCrawler()->selectButton('Guardar')->form([
            'budget_limit' => '5000,00',
            'budget_mode' => AiBudgetMode::HARD->value,
        ]));

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-region', 'El plan permite un presupuesto de IA de 10000 céntimos como máximo.');

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $guard = self::getContainer()->get(AiBudgetGuard::class);

        $status = $tenantContext->runAs($this->organizationId, static fn (): mixed => $guard->status());

        self::assertSame(5000, $status->limitMinor(), 'El tope anterior se conserva.');
        self::assertSame(AiBudgetMode::SOFT, $status->mode, 'El modo anterior se conserva.');
    }

    public function testTheBudgetCanBeSavedFromTheSettingsScreen(): void
    {
        $this->client->request('GET', '/ai');

        $this->client->submit($this->client->getCrawler()->selectButton('Guardar')->form([
            'budget_limit' => '25,00',
            'budget_mode' => AiBudgetMode::HARD->value,
        ]));

        self::assertResponseRedirects('/ai');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-region', 'configuración de IA');

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $guard = self::getContainer()->get(AiBudgetGuard::class);

        $status = $tenantContext->runAs($this->organizationId, static fn (): mixed => $guard->status());

        self::assertSame(2500, $status->limitMinor());
        self::assertSame(AiBudgetMode::HARD, $status->mode);
    }

    public function testAnInvalidBudgetLimitIsRejectedAndTheOldOneIsKept(): void
    {
        $this->configureBudget(1000, AiBudgetMode::SOFT);

        $this->client->request('GET', '/ai');

        $this->client->submit($this->client->getCrawler()->selectButton('Guardar')->form([
            'budget_limit' => 'no es un importe',
            'budget_mode' => AiBudgetMode::HARD->value,
        ]));

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-region', 'no es un importe válido');

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $guard = self::getContainer()->get(AiBudgetGuard::class);

        $status = $tenantContext->runAs($this->organizationId, static fn (): mixed => $guard->status());

        self::assertSame(1000, $status->limitMinor());
        self::assertSame(AiBudgetMode::SOFT, $status->mode);
    }

    public function testTheReleaseButtonNeedsAValidCsrfToken(): void
    {
        $this->client->request('POST', '/ai/cost', ['_token' => 'inventado']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheReleaseButtonIsNotShownWhenThereIsNothingToRelease(): void
    {
        $this->client->request('GET', '/ai/cost');

        self::assertResponseIsSuccessful();

        // Sin correos aplazados el botón ni siquiera aparece: no hay nada que
        // hacer y no se quiere enseñar un botón que no va a pasar nada.
        self::assertCount(0, $this->client->getCrawler()->filter('form[action="/ai/cost"]'));
    }

    public function testThePanelAnnouncesTheDeferredMessagesAndOffersToReleaseThem(): void
    {
        $account = $this->createAccount();
        $message = $this->createInvoicelikeMessage($account);

        $this->exhaustTheBudget();
        $this->stubImapWithInvoice();
        $this->runPipeline($message);

        $this->client->request('GET', '/ai/cost');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.bo-main', 'Hay');
        self::assertSelectorTextContains('.bo-main', 'correos aplazados');
        self::assertCount(1, $this->client->getCrawler()->filter('form[action="/ai/cost"]'));
    }

    public function testTheReleaseButtonPutsTheDeferredMessagesBackInTheQueue(): void
    {
        $account = $this->createAccount();
        $message = $this->createInvoicelikeMessage($account);

        $this->exhaustTheBudget();
        $this->stubImapWithInvoice();
        $this->runPipeline($message);

        // El mes ya tiene presupuesto (por ejemplo, porque el tope se subió
        // en otro sitio): el botón sirve para reintentar a mano.
        $this->configureBudgetLimit(10000);

        $this->client->request('GET', '/ai/cost');

        $this->client->submit($this->client->getCrawler()->filter('form[action="/ai/cost"]')->form());

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-region', 'Se han puesto en cola 1 correos aplazados');
    }

    private function recordUsage(int $costMinor, string $providerKey, AiPurpose $purpose): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $usages = self::getContainer()->get(AiUsageRepositoryInterface::class);
        $organizationId = $this->organizationId;

        $tenantContext->runAs($organizationId, static function () use ($usages, $organizationId, $costMinor, $providerKey, $purpose): void {
            $usages->save(new AiUsage(
                organizationId: $organizationId,
                providerKey: $providerKey,
                purpose: $purpose,
                tier: AiTier::CHEAP,
                model: 'modelo-de-prueba',
                promptTokens: 1200,
                completionTokens: 300,
                costMicro: AiCost::toMicro($costMinor),
                createdAt: new DateTimeImmutable(),
            ));
        });
    }

    private function configureBudget(int $limitMinor, AiBudgetMode $mode): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $guard = self::getContainer()->get(AiBudgetGuard::class);

        $tenantContext->runAs(
            $this->organizationId,
            static function () use ($guard, $limitMinor, $mode): void {
                $guard->configure($limitMinor, $mode);
            },
        );
    }
}
