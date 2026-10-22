<?php

declare(strict_types=1);

namespace App\Demo\Application;

use App\Ai\Application\AiPriceCatalog;
use App\Ai\Application\AiPurposeSelector;
use App\Ai\Domain\Entity\AiUsage;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Ai\Domain\Repository\AiUsageRepositoryInterface;
use App\Catalog\Application\SeedCatalog;
use App\Catalog\Domain\Repository\CategoryRepositoryInterface;
use App\Catalog\Domain\Repository\ProviderRepositoryInterface;
use App\Discovery\Application\Change\DetectChanges;
use App\Identity\Application\ChangeOrganizationPlan;
use App\Identity\Application\CompleteOnboarding;
use App\Identity\Application\CreateOrganization;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Enum\OrganizationPlan;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Integrations\Application\IntegrationConfigWriter;
use App\Integrations\Application\IntegrationRegistry;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Entity\EmailMessage;
use App\Mailbox\Domain\Entity\EmailSyncRun;
use App\Mailbox\Domain\Enum\EmailAccountProvider;
use App\Mailbox\Domain\Enum\ExtractionTier;
use App\Mailbox\Domain\Enum\ImapEncryption;
use App\Mailbox\Domain\Enum\MessageClassification;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailSyncRunRepositoryInterface;
use App\Notifications\Application\GenerateAlerts;
use App\Services\Application\ChangeServicePrice;
use App\Services\Application\CreateService;
use App\Services\Application\Dto\ServiceInput;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Entitlements\EntitlementSettings;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;

use function array_key_exists;
use function array_key_last;
use function array_slice;
use function count;

use DateTimeImmutable;

use function in_array;
use function max;

use RuntimeException;

use function sprintf;

use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Siembra un tenant de demostración con datos que se parecen a los de verdad.
 *
 * ## Para qué existe
 *
 * Para poder **enseñar** el producto: un panel vacío no dice nada de cómo
 * funciona la evolución del gasto, de los avisos ni de la bandeja de
 * descubrimientos. Con datos sembrados, se ve lo que se ve.
 *
 * ## Cómo se hace: con los casos de uso, no con SQL
 *
 * Este módulo no escribe en ninguna tabla. Llama a los mismos casos de uso que
 * usa la aplicación —`RegisterUser`, `CreateService`, `ChangeServicePrice`,
 * `DetectChanges`, `GenerateAlerts`— de modo que lo sembrado pasa por las
 * mismas reglas que lo real: las cuotas del plan, la auditoría, los avisos y
 * las propuestas. Si el sembrador se saltaba esas reglas, la demostración
 * enseñaría un producto que no existe.
 *
 * La única excepción son las filas de **uso de IA**, que no tienen caso de uso
 * público: se guardan con su repositorio, que es una interfaz publicada del
 * módulo de IA.
 *
 * ## Es idempotente
 *
 * Se puede ejecutar tantas veces como se quiera: lo que ya existe no se
 * duplica. Los servicios se reconocen por su nombre, el barrido de cambios
 * deduplica por su propia clave y los avisos por la suya. La contraseña de la
 * cuenta sí se reescribe, para que el comando deje la cuenta siempre en un
 * estado conocido.
 */
final readonly class SeedDemoTenant
{
    /**
     * El nombre que se ve en la interfaz. La organización personal se crea con
     * este nombre, así que el panel dice «Estudio Demo» y no «Ada Lovelace».
     */
    private const string DISPLAY_NAME = 'Estudio Demo';

    /**
     * Una segunda organización, para que el selector de organizaciones tenga
     * algo que cambiar y se vea que el aislamiento por tenant funciona.
     */
    private const string SECOND_ORGANIZATION = 'Acme Consulting';

    /**
     * El buzón de la demostración.
     *
     * Es un dominio falso a propósito: si el seeder intentara conectarse de
     * verdad, la demostración dependería de un servidor de correo que no
     * existe.
     */
    private const string DEMO_MAILBOX_ADDRESS = 'facturacion@acme-demo.local';

    /**
     * Los correos de la demostración.
     *
     * Cada uno recorre el pipeline un camino distinto —determinista, parser
     * conocido, IA económica, IA avanzada, aplazado y fallido— para que el
     * panel de observabilidad (Fase 17) enseñe el reparto real y no un
     * contador plano.
     *
     * @var list<array{
     *     subject: string,
     *     from: string,
     *     fromName: string,
     *     daysAgo: int,
     *     size: int,
     *     score: int,
     *     confidence: int,
     *     classification: MessageClassification,
     *     tier: ExtractionTier,
     *     extractor: string,
     *     state: MessageProcessingState,
     *     aiCostMicro: int,
     *     excerpt: string,
     *     attachments: list<string>
     * }>
     */
    private const array DEMO_MESSAGES = [
        [
            'subject' => 'Factura #2024-018 — Hosting Acme',
            'from' => 'facturas@acme-demo.local',
            'fromName' => 'Facturación Acme',
            'daysAgo' => 1,
            'size' => 48210,
            'score' => 92,
            'confidence' => 96,
            'classification' => MessageClassification::INVOICE,
            'tier' => ExtractionTier::DETERMINISTIC,
            'extractor' => 'invoice-pattern',
            'state' => MessageProcessingState::MATCHED,
            'aiCostMicro' => 0,
            'excerpt' => 'Factura por importe de 89,00 EUR. Periodo facturado: mayo de 2026. hosting acme.',
            'attachments' => ['factura-2024-018.pdf'],
        ],
        [
            'subject' => 'Tu recibo de Vercel — mayo 2026',
            'from' => 'billing@vercel-demo.local',
            'fromName' => 'Vercel',
            'daysAgo' => 2,
            'size' => 22140,
            'score' => 88,
            'confidence' => 94,
            'classification' => MessageClassification::RECEIPT,
            'tier' => ExtractionTier::KNOWN_PARSER,
            'extractor' => 'vercel-parser',
            'state' => MessageProcessingState::CLASSIFIED,
            'aiCostMicro' => 0,
            'excerpt' => 'Recibo de Vercel por 20,00 USD. Plan hobby. Se renueva el proximo mes.',
            'attachments' => ['receipt-vercel.pdf'],
        ],
        [
            'subject' => 'Confirmacion de pago a DigitalOcean',
            'from' => 'no-reply@digitalocean-demo.local',
            'fromName' => 'DigitalOcean',
            'daysAgo' => 3,
            'size' => 15200,
            'score' => 79,
            'confidence' => 88,
            'classification' => MessageClassification::PAYMENT_CONFIRMATION,
            'tier' => ExtractionTier::DETERMINISTIC,
            'extractor' => 'payment-pattern',
            'state' => MessageProcessingState::MATCHED,
            'aiCostMicro' => 0,
            'excerpt' => 'Hemos recibido tu pago de 48,00 EUR. Gracias por tu confianza.',
            'attachments' => [],
        ],
        [
            'subject' => 'Resumen de consumo de OpenAI — mayo 2026',
            'from' => 'billing@openai-demo.local',
            'fromName' => 'OpenAI',
            'daysAgo' => 4,
            'size' => 63050,
            'score' => 84,
            'confidence' => 82,
            'classification' => MessageClassification::INVOICE,
            'tier' => ExtractionTier::AI_CHEAP,
            'extractor' => 'llama-3.3-70b-versatile',
            'state' => MessageProcessingState::EXTRACTED,
            'aiCostMicro' => 42_000,
            'excerpt' => 'Consumo del periodo: 12,40 EUR. Detalle de uso de modelos yokens incluidos.',
            'attachments' => ['openai-usage.pdf'],
        ],
        [
            'subject' => 'Contrato anual de Figma — condiciones de renovacion',
            'from' => 'contracts@figma-demo.local',
            'fromName' => 'Figma',
            'daysAgo' => 6,
            'size' => 184300,
            'score' => 71,
            'confidence' => 64,
            'classification' => MessageClassification::RENEWAL_NOTICE,
            'tier' => ExtractionTier::AI_ADVANCED,
            'extractor' => 'gpt-4o-mini',
            'state' => MessageProcessingState::REQUIRES_REVIEW,
            'aiCostMicro' => 168_000,
            'excerpt' => 'Su plan Professional se renueva automaticamente dentro de once meses. Importe 144,00 EUR.',
            'attachments' => ['contrato-figma.pdf'],
        ],
        [
            'subject' => 'Aviso: tu tarjeta de credito sera renovada',
            'from' => 'no-reply@stripe-demo.local',
            'fromName' => 'Stripe',
            'daysAgo' => 8,
            'size' => 9800,
            'score' => 44,
            'confidence' => 70,
            'classification' => MessageClassification::OTHER,
            'tier' => ExtractionTier::DETERMINISTIC,
            'extractor' => 'noise-filter',
            'state' => MessageProcessingState::IGNORED,
            'aiCostMicro' => 0,
            'excerpt' => 'La tarjeta terminada en 4242 sera renovada el proximo mes.',
            'attachments' => [],
        ],
        [
            'subject' => 'Novedades del producto — julio',
            'from' => 'newsletter@saas-demo.local',
            'fromName' => 'SaaS Weekly',
            'daysAgo' => 9,
            'size' => 74000,
            'score' => 8,
            'confidence' => 40,
            'classification' => MessageClassification::OTHER,
            'tier' => ExtractionTier::DETERMINISTIC,
            'extractor' => 'noise-filter',
            'state' => MessageProcessingState::IGNORED,
            'aiCostMicro' => 0,
            'excerpt' => 'Las diez noticias mas importantes del sector esta semana.',
            'attachments' => [],
        ],
        [
            'subject' => 'Factura pendiente de Hetzner Cloud',
            'from' => 'invoice@hetzner-demo.local',
            'fromName' => 'Hetzner',
            'daysAgo' => 11,
            'size' => 21100,
            'score' => 90,
            'confidence' => 95,
            'classification' => MessageClassification::INVOICE,
            'tier' => ExtractionTier::KNOWN_PARSER,
            'extractor' => 'hetzner-parser',
            'state' => MessageProcessingState::CLASSIFIED,
            'aiCostMicro' => 0,
            'excerpt' => 'Factura pendiente por 12,30 EUR. Numero de factura 998877.',
            'attachments' => ['hetzner-invoice.pdf'],
        ],
        [
            'subject' => 'Cambio de precio en tu plan de Notion',
            'from' => 'team@notion-demo.local',
            'fromName' => 'Notion',
            'daysAgo' => 13,
            'size' => 12400,
            'score' => 66,
            'confidence' => 61,
            'classification' => MessageClassification::PRICE_CHANGE,
            'tier' => ExtractionTier::AI_CHEAP,
            'extractor' => 'llama-3.3-70b-versatile',
            'state' => MessageProcessingState::DISCOVERY,
            'aiCostMicro' => 38_000,
            'excerpt' => 'El precio de tu plan cambia de 10,00 a 12,00 USD a partir del proximo ciclo.',
            'attachments' => [],
        ],
        [
            'subject' => 'Recibimos tu correo de第一季度',
            'from' => 'facturas@acme-demo.local',
            'fromName' => 'Facturación Acme',
            'daysAgo' => 16,
            'size' => 19800,
            'score' => 86,
            'confidence' => 91,
            'classification' => MessageClassification::INVOICE,
            'tier' => ExtractionTier::DETERMINISTIC,
            'extractor' => 'invoice-pattern',
            'state' => MessageProcessingState::MATCHED,
            'aiCostMicro' => 0,
            'excerpt' => 'Factura trimestral por 267,00 EUR. Periodo facturado: primer trimestre de 2026.',
            'attachments' => ['factura-trimestral.pdf'],
        ],
        [
            'subject' => 'Suspension de servicio programada',
            'from' => 'status@cloud-demo.local',
            'fromName' => 'Cloud Provider',
            'daysAgo' => 18,
            'size' => 8400,
            'score' => 38,
            'confidence' => 55,
            'classification' => MessageClassification::OTHER,
            'tier' => ExtractionTier::DETERMINISTIC,
            'extractor' => 'noise-filter',
            'state' => MessageProcessingState::IGNORED,
            'aiCostMicro' => 0,
            'excerpt' => 'Mantenimiento programado el domingo de 02:00 a 04:00.',
            'attachments' => [],
        ],
        [
            'subject' => 'Factura de AWS Lightsail — junio 2026',
            'from' => 'no-reply@aws-demo.local',
            'fromName' => 'AWS',
            'daysAgo' => 20,
            'size' => 27300,
            'score' => 89,
            'confidence' => 90,
            'classification' => MessageClassification::INVOICE,
            'tier' => ExtractionTier::AI_ADVANCED,
            'extractor' => 'gpt-4o-mini',
            'state' => MessageProcessingState::REQUIRES_REVIEW,
            'aiCostMicro' => 205_000,
            'excerpt' => 'Importe 5,00 USD. Instancia Lightsail de 2 GB durante mayo de 2026.',
            'attachments' => ['aws-lightsail.pdf'],
        ],
        [
            // El camino fallido: la IA agotó el tiempo de espera. Sin este
            // mensaje, el panel de observabilidad enseñaría una tasa de
            // fallos siempre a cero y nadie vería cómo se ve un error.
            'subject' => 'Factura de OpenAI — junio 2026',
            'from' => 'billing@openai-demo.local',
            'fromName' => 'OpenAI',
            'daysAgo' => 21,
            'size' => 19800,
            'score' => 87,
            'confidence' => 0,
            'classification' => MessageClassification::INVOICE,
            'tier' => ExtractionTier::AI_CHEAP,
            'extractor' => 'gpt-4o-mini',
            'state' => MessageProcessingState::FAILED,
            'aiCostMicro' => 0,
            'excerpt' => 'Factura recibida, pero no se pudo leer: el proveedor de IA no respondió a tiempo.',
            'attachments' => ['openai-junio.pdf'],
        ],
    ];

    public function __construct(
        private DemoAccount $account,
        private RegisterUser $registerUser,
        private UserRepositoryInterface $users,
        private CreateOrganization $createOrganization,
        private OrganizationRepositoryInterface $organizations,
        private ChangeOrganizationPlan $changePlan,
        private CompleteOnboarding $completeOnboarding,
        private SeedCatalog $seedCatalog,
        private CategoryRepositoryInterface $categories,
        private ProviderRepositoryInterface $providers,
        private CreateService $createService,
        private ServiceRepositoryInterface $services,
        private ChangeServicePrice $changePrice,
        private DetectChanges $detectChanges,
        private GenerateAlerts $generateAlerts,
        private AiUsageRepositoryInterface $aiUsage,
        private AiPriceCatalog $aiPrices,
        private AiPurposeSelector $aiSelector,
        private IntegrationConfigWriter $integrations,
        private EmailAccountRepositoryInterface $emailAccounts,
        private EmailMessageRepositoryInterface $emailMessages,
        private EmailSyncRunRepositoryInterface $syncRuns,
        private TenantContext $tenantContext,
        private UserPasswordHasherInterface $passwordHasher,
        private EntitlementSettings $entitlements,
    ) {
    }

    public function __invoke(): DemoSeedResult
    {
        $now = new DateTimeImmutable();

        // El catálogo global es idempotente y no necesita contexto de tenant:
        // es compartido por todas las organizaciones.
        ($this->seedCatalog)();

        $user = $this->users->findByEmail($this->account->email);
        $userCreated = false;

        if (null === $user) {
            $user = ($this->registerUser)($this->account->email, $this->account->password, self::DISPLAY_NAME);
            $userCreated = true;
        } else {
            // La contraseña se reescribe: el comando deja la cuenta en un
            // estado conocido aunque alguien la haya cambiado.
            $user->setPasswordHash($this->passwordHasher->hashPassword($user, $this->account->password));
        }

        $user->markVerified();
        $this->users->save($user, false);

        $organization = $this->primaryOrganization($user);

        // Plan PRO: sin techo de servicios y con la inteligencia artificial
        // disponible. Con el plan gratuito el catálogo de demostración no
        // cabría, y el panel se vería limitado de una forma que no es real.
        ($this->changePlan)($organization, OrganizationPlan::PRO, $this->entitlements->planNames());

        // La guía de bienvenida se da por vista: la cuenta de demostración entra
        // directa al panel, que es lo que se quiere enseñar.
        ($this->completeOnboarding)($user);

        $this->ensureSecondOrganization($user);

        $seeded = $this->tenantContext->runAs(
            $organization->getId(),
            fn (): array => $this->seedServices($user, $now),
        );

        // El barrido va antes que los avisos: así los avisos también hablan
        // de las propuestas que acaba de crear el barrido.
        $detection = $this->tenantContext->runAs(
            $organization->getId(),
            fn () => ($this->detectChanges)(),
        );

        $alerts = $this->tenantContext->runAs(
            $organization->getId(),
            fn () => ($this->generateAlerts)(),
        );

        $this->configureAi($organization);
        $aiCalls = $this->seedAiUsage($organization, $seeded['services'], $now);

        $this->tenantContext->runAs(
            $organization->getId(),
            fn (): int => $this->seedMailActivity($organization, $now),
        );

        return new DemoSeedResult(
            email: $this->account->email,
            password: $this->account->password,
            userCreated: $userCreated,
            organizationName: $organization->getName(),
            servicesCreated: $seeded['created'],
            servicesSkipped: $seeded['skipped'],
            proposals: $detection->total(),
            alertsCreated: $alerts->created,
            aiCalls: $aiCalls,
        );
    }

    /**
     * La organización con la que se siembra.
     *
     * Es la **personal** del usuario: la que crea el alta y la que el sistema
     * elige como activa cuando no hay ninguna seleccionada. Sembrar en otra
     * obligaría a forzar la selección en cada sesión, y la demostración
     * perdería el primer clic.
     *
     * Se busca por la marca `personal` y no por «la primera de la lista»
     * porque esa lista viene ordenada por fecha de creación, y dos
     * organizaciones creadas en el mismo instante —la personal y la segunda,
     * que se crean casi juntas— quedan en un orden que no está garantizado.
     * Elegir la personal por su marca hace la siembra determinista: sembrar
     * dos veces siempre siembra en la misma organización.
     */
    private function primaryOrganization(User $user): Organization
    {
        $organizations = $this->organizations->findForUser($user);

        if ([] === $organizations) {
            throw new RuntimeException('El usuario de demostración no tiene ninguna organización.');
        }

        foreach ($organizations as $membership) {
            if ($membership['organization']->isPersonal()) {
                return $membership['organization'];
            }
        }

        // Sin organización personal se usa la primera: el objetivo es sembrar
        // en alguna, y la personal es solo la más cómoda.
        return $organizations[0]['organization'];
    }

    /**
     * Crea la segunda organización si no existe.
     *
     * Se queda sin datos a propósito: sirve para ver que cambiar de
     * organización cambia el panel entero, que es de lo que se trata el
     * aislamiento por tenant.
     */
    private function ensureSecondOrganization(User $user): void
    {
        foreach ($this->organizations->findForUser($user) as $membership) {
            if (self::SECOND_ORGANIZATION === $membership['organization']->getName()) {
                return;
            }
        }

        ($this->createOrganization)($user, self::SECOND_ORGANIZATION);
    }

    /**
     * Siembra el inventario de servicios.
     *
     * @return array{created: int, skipped: int, services: list<Service>}
     */
    private function seedServices(User $user, DateTimeImmutable $now): array
    {
        $existing = [];

        foreach ($this->services->findForOrganization(ServiceFilters::none()) as $service) {
            $existing[$service->getName()] = true;
        }

        $created = 0;
        $skipped = 0;
        $services = [];

        foreach (self::demoServices() as $definition) {
            if (isset($existing[$definition['name']])) {
                ++$skipped;

                continue;
            }

            $service = $this->createDemoService($definition, $user, $now);
            $services[] = $service;
            $existing[$definition['name']] = true;
            ++$created;
        }

        return ['created' => $created, 'skipped' => $skipped, 'services' => $services];
    }

    /**
     * @param array{name: string, provider: string|null, category: string, period: BillingPeriod, prices: list<array{monthsAgo: int, amount: int}>, currency?: Currency, nextChargeInDays?: int, renewalInDays?: int, noticeDays?: int, plan?: string, status?: string, paymentMethod?: string, notes?: string} $definition
     */
    private function createDemoService(array $definition, User $user, DateTimeImmutable $now): Service
    {
        $provider = null;

        if (null !== $definition['provider']) {
            $provider = $this->providers->findGlobalBySlug($definition['provider']);
        }

        $category = $this->categories->findGlobalBySlug($definition['category']);

        $prices = $definition['prices'];
        $first = $prices[0];

        // Por defecto todo en euros. La moneda va en la definición porque el
        // panel enseña, a propósito, un servicio que se paga en otra.
        $currency = $definition['currency'] ?? Currency::EUR;

        $input = new ServiceInput(
            name: $definition['name'],
            currency: $currency,
            billingPeriod: $definition['period'],
            amount: Money::of($first['amount'], $currency),
            providerId: $provider?->getId(),
            categoryId: $category?->getId(),
            planName: $definition['plan'] ?? null,
            startedAt: $this->monthsAgo($now, $first['monthsAgo']),
            nextChargeAt: $this->nextChargeAt($now, $definition),
            renewalAt: $this->renewalAt($now, $definition),
            noticePeriodDays: $definition['noticeDays'] ?? null,
            paymentMethodLabel: $definition['paymentMethod'] ?? null,
            notes: $definition['notes'] ?? null,
        );

        $service = ($this->createService)($input, $user->getId());

        // El historial de precios: cada cambio cierra el anterior y abre el
        // siguiente. Sin esto, la evolución del gasto sería una línea plana y
        // el panel no enseñaría nada.
        foreach (array_slice($prices, 1) as $price) {
            ($this->changePrice)(
                $service,
                Money::of($price['amount'], $currency),
                $this->monthsAgo($now, $price['monthsAgo']),
                'Precio actualizado por el proveedor.',
                $user->getId(),
            );
        }

        $status = $definition['status'] ?? null;

        if ('paused' === $status) {
            $service->pause($user->getId());
            $this->services->save($service);
        }

        if ('cancelled' === $status) {
            $service->cancel($now, $user->getId());
            $this->services->save($service);
        }

        return $service;
    }

    /**
     * Configura un proveedor de IA de ejemplo y deja la organización con la
     * elección hecha.
     *
     * La clave que se guarda **no es real**: es un texto de relleno para que
     * el panel de integraciones y el de IA tengan contenido. Llama a un
     * proveedor con ella, la llamada falla y el mensaje va a revisión, que es
     * justo lo que tiene que pasar con una clave inválida.
     */
    private function configureAi(Organization $organization): void
    {
        $this->tenantContext->runAs($organization->getId(), function (): void {
            $this->integrations->save(
                key: IntegrationRegistry::KEY_GROQ,
                label: 'Groq (demostración)',
                baseUrl: 'https://api.groq.com/openai/v1',
                credentials: ['api_key' => 'gsk_demo_clave_de_ejemplo'],
                settings: [
                    'model' => 'llama-3.3-70b-versatile',
                    'max_tokens' => '2048',
                ],
                enabled: true,
            );

            $this->aiSelector->setEnabled(true);
            $this->aiSelector->saveChoices([
                AiPurpose::EXTRACTION->value => IntegrationRegistry::KEY_GROQ,
                AiPurpose::SUMMARIZATION->value => IntegrationRegistry::KEY_GROQ,
                AiPurpose::CLASSIFICATION->value => IntegrationRegistry::KEY_GROQ,
            ]);
        });
    }

    /**
     * Siembra el historial de gasto en IA.
     *
     * Se registra con fechas en el pasado y el coste calculado con el catálogo
     * de precios, de modo que el panel de coste enseña algo coherente sin
     * gastar un céntimo de verdad ni llamar a ningún proveedor.
     *
     * Las llamadas se reparten por los últimos veinte días, que es lo que
     * abarca el mes en curso: así el panel de presupuesto enseña el consumo
     * del mes, que es el número que se mira.
     *
     * @param list<Service> $services
     */
    private function seedAiUsage(Organization $organization, array $services, DateTimeImmutable $now): int
    {
        // Idempotencia: si ya hay consumo registrado, no se vuelve a sembrar.
        // El registro de uso no tiene clave natural con la que deduplicar.
        if ([] !== $this->aiUsage->findForOrganization($organization->getId())) {
            return 0;
        }

        $created = 0;
        $usages = [];

        for ($i = 0; $i < 42; ++$i) {
            $advanced = 0 === $i % 7;
            $providerKey = $advanced ? 'openai' : 'groq';
            $purpose = match ($i % 3) {
                0 => $advanced ? AiPurpose::ADVANCED_EXTRACTION : AiPurpose::EXTRACTION,
                1 => AiPurpose::SUMMARIZATION,
                default => AiPurpose::CLASSIFICATION,
            };
            $tier = $advanced ? AiTier::ADVANCED : AiTier::CHEAP;
            $model = $advanced ? 'gpt-4o-mini' : 'llama-3.3-70b-versatile';
            $promptTokens = 2800 + (($i * 173) % 5200);
            $completionTokens = 240 + (($i * 61) % 900);
            $price = $this->aiPrices->for($providerKey);
            $service = $services[$i % max(1, count($services))] ?? null;

            $usages[] = new AiUsage(
                organizationId: $organization->getId(),
                providerKey: $providerKey,
                purpose: $purpose,
                tier: $tier,
                model: $model,
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                costMicro: $price->costMicro($promptTokens, $completionTokens),
                successful: !in_array($i, [5, 17, 31], true),
                durationMs: 900 + (($i * 97) % 2400),
                entityType: $service instanceof Service ? 'service' : null,
                entityId: $service?->getId(),
                createdAt: $now->modify(sprintf('-%d days', $i % 21)),
            );
            ++$created;
        }

        // El ultimo guardado es el que vacia la cola: los anteriores se quedan
        // en ella y se escriben todos juntos, en una sola transaccion.
        $last = array_key_last($usages);
        foreach ($usages as $index => $usage) {
            $this->aiUsage->save($usage, $index === $last);
        }

        return $created;
    }

    /**
     * Siembra la actividad de correo: buzón, mensajes y pasadas de sincronización.
     *
     * ## Por qué hace falta
     *
     * El producto promises "conecta tu correo y descubre lo que pagas". Sin
     * buzón ni mensajes, el panel de observabilidad (Fase 17) enseña ceros y
     * no dice nada de cómo trabaja el pipeline. Con esta siembra se ve la
     * cadena entera: entra el correo, se clasifica, unos correos los resuelve
     * el motor determinista y otros necesitan IA, y una de las pasadas
     * falló.
     *
     * ## Por qué se escribe con las entidades y no con casos de uso
     *
     * Los casos de uso del módulo de correo (leer buzón, procesar mensaje)
     *hablaría con un servidor IMAP de verdad. La demo no puede fingir que entra en un
     * servidor de correo: si lo hiciera, la demostración dependería de que
     * hubiera un servidor de correo de mentira, que es un mundo entero de
     * problemas para enseñar un panel. Se escriben las entidades con el
     * mismo estado que dejaría el pipeline, y el panel de observabilidad
     * enseña lo mismo que enseñaría con correo real.
     *
     * ## Es idempotente
     *
     * Si ya hay mensajes en la organización, no se vuelve a sembrar: el
     * buzón se reconoce por su dirección.
     */
    private function seedMailActivity(Organization $organization, DateTimeImmutable $now): int
    {
        $account = $this->demoMailbox($organization, $now);

        if ($this->emailMessages->countForOrganization() > 0) {
            return 0;
        }

        $created = 0;
        $uid = 1000;

        foreach (self::DEMO_MESSAGES as $definition) {
            $receivedAt = $now->modify(sprintf('-%d days', $definition['daysAgo']));

            $message = new EmailMessage(
                organizationId: $organization->getId(),
                emailAccountId: $account->getId(),
                folder: 'INBOX',
                uid: $uid,
            );

            $message->applyMetadata(
                messageId: sprintf('<demo-%d@acme-demo.local>', $uid),
                fromAddress: $definition['from'],
                fromName: $definition['fromName'],
                replyTo: $definition['from'],
                senderDomain: 'acme-demo.local',
                toAddresses: [$account->getEmailAddress()],
                subject: $definition['subject'],
                receivedAt: $receivedAt,
                sizeBytes: $definition['size'],
                contentType: 'text/plain; charset=utf-8',
                attachmentNames: $definition['attachments'],
                attachmentTypes: $definition['attachments'] === [] ? [] : ['application/pdf'],
            );

            $message->setContentHash(hash('sha256', $definition['subject'].$uid));
            $message->setBodyExcerpt($definition['excerpt']);
            $message->applyBillingScore($definition['score'], [['signal' => 'demo', 'weight' => $definition['score'], 'detail' => 'Mensaje sembrado']]);
            $message->applyClassification($definition['classification'], $definition['confidence']);
            $message->recordExtraction(
                $definition['tier'],
                $definition['extractor'],
                $definition['tier']->usedAi(),
                $definition['aiCostMicro'],
            );
            $message->setProcessingState($definition['state']);
            // La duración va en segundos y en magnitudes realistas: procesar un
            // correo tarda unos segundos, no unos minutos.
            $message->markProcessed($receivedAt->modify('+'.(2 + ($uid % 7)).' seconds'));

            if (MessageProcessingState::FAILED === $definition['state']) {
                $message->recordFailure('El proveedor de IA no respondió a tiempo (se agotó el tiempo de espera).');
            }

            $this->emailMessages->save($message, false);
            ++$uid;
            ++$created;
        }

        $this->emailMessages->flush();
        $this->seedSyncRuns($organization, $account, $now);

        return $created;
    }

    /**
     * El buzón de la demostración.
     *
     * Si ya existe una cuenta con esa dirección, se reutiliza: sembrar dos
     * veces no puede crear dos buzones con la misma dirección (la base de
     * datos lo impediría, pero es mejor no intentarlo).
     */
    private function demoMailbox(Organization $organization, DateTimeImmutable $now): EmailAccount
    {
        $address = self::DEMO_MAILBOX_ADDRESS;

        foreach ($this->emailAccounts->findForOrganization() as $existing) {
            if ($existing->getEmailAddress() === $address) {
                return $existing;
            }
        }

        $account = new EmailAccount($organization->getId(), $address, EmailAccountProvider::IMAP);
        $account->setDisplayName('Facturación de Acme');
        $account->configureImap(
            host: 'imap.acme-demo.local',
            encryption: ImapEncryption::SSL,
            port: 993,
            username: $address,
        );
        $account->markActive();

        $this->emailAccounts->save($account);

        return $account;
    }

    /**
     * Las pasadas de sincronización de los últimos días.
     *
     * Una de ellas falla a propósito: un panel de observabilidad donde todo
     * sale bien no enseña a leer el panel. El error que se guarda es el
     * típico (el servidor de correo no respondió), que es el que de verdad
     * se ve en producción.
     */
    private function seedSyncRuns(Organization $organization, EmailAccount $account, DateTimeImmutable $now): void
    {
        $runs = [];

        for ($day = 0; $day < 9; ++$day) {
            $startedAt = $now->modify(sprintf('-%d days -%d hours', $day, 6));
            $run = new EmailSyncRun($organization->getId(), $account->getId(), $startedAt);

            $failed = 2 === $day;

            $run->countSeen(18 + (($day * 5) % 22));
            $run->countProcessed(9 + (($day * 3) % 14));
            $run->countSkipped(6 + ($day % 5));
            $run->countDiscovery(($day % 4) + 1);

            if ($failed) {
                $run->fail($startedAt->modify('+45 seconds'), 'El servidor IMAP no respondió a tiempo.');
            } else {
                $run->complete($startedAt->modify('+'.(12 + ($day * 3)).' seconds'));
            }

            $runs[] = $run;
        }

        $last = array_key_last($runs);

        foreach ($runs as $index => $run) {
            $this->syncRuns->save($run, $index === $last);
        }
    }

    /**
     * @param array{nextChargeInDays?: int} $definition
     */
    private function nextChargeAt(DateTimeImmutable $now, array $definition): ?DateTimeImmutable
    {
        if (!array_key_exists('nextChargeInDays', $definition)) {
            // Sin fecha explícita la calcula el propio servicio a partir de la
            // fecha de alta, que es lo que hace la aplicación real.
            return null;
        }

        return $now->modify(sprintf('%+d days', $definition['nextChargeInDays']));
    }

    /**
     * @param array{renewalInDays?: int} $definition
     */
    private function renewalAt(DateTimeImmutable $now, array $definition): ?DateTimeImmutable
    {
        if (!array_key_exists('renewalInDays', $definition)) {
            return null;
        }

        return $now->modify(sprintf('%+d days', $definition['renewalInDays']));
    }

    private function monthsAgo(DateTimeImmutable $now, int $months): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d months', $months));
    }

    /**
     * El inventario de demostración.
     *
     * ## Cómo está pensado
     *
     * - **Precios con historia**: varios servicios suben de precio hace unos
     *   meses, para que la evolución del gasto no sea plana y se vean los
     *   avisos de subida.
     * - **Cobros repartidos**: hay cobros inminentes (a dos y a tres días, que
     *   son urgentes) y otros dentro de un mes.
     * - **Renovaciones y preavisos**: dos servicios tienen fecha de renovación
     *   con preaviso, que es lo que genera los avisos de «no renueves».
     * - **Un posible duplicado**: dos servidores de Hetzner con el mismo
     *   precio. El barrido de cambios lo detecta y lo propone; no lo corrige.
     * - **Un cobro que no llegó**: el coworking tenía que haber cobrado hace
     *   dos semanas y no hay factura.
     * - **Un servicio dormido**: la licencia de antivirus lleva más de seis
     *   meses sin dar señales de vida.
     * - **Estados distintos**: hay un servicio pausado y uno cancelado, para
     *   que el panel no enseñe un inventario plano.
     *
     * @return list<array{name: string, provider: string|null, category: string, period: BillingPeriod, prices: list<array{monthsAgo: int, amount: int}>, nextChargeInDays?: int, renewalInDays?: int, noticeDays?: int, plan?: string, status?: string, paymentMethod?: string, notes?: string}>
     */
    private static function demoServices(): array
    {
        return [
            [
                'name' => 'Hetzner Cloud Server CX22',
                'provider' => 'hetzner',
                'category' => 'hosting',
                'period' => BillingPeriod::MONTHLY,
                'prices' => [
                    ['monthsAgo' => 4, 'amount' => 1090],
                    ['monthsAgo' => 2, 'amount' => 1290],
                ],
                'nextChargeInDays' => 12,
                'plan' => 'CX22',
                'paymentMethod' => 'Tarjeta terminada en 4242',
                'notes' => 'Servidor de producción donde vive la web.',
            ],
            [
                'name' => 'Hetzner Cloud Server CX22 (réplica)',
                'provider' => 'hetzner',
                'category' => 'hosting',
                'period' => BillingPeriod::MONTHLY,
                'prices' => [
                    ['monthsAgo' => 4, 'amount' => 1090],
                    ['monthsAgo' => 2, 'amount' => 1290],
                ],
                'nextChargeInDays' => 12,
                'plan' => 'CX22',
                'paymentMethod' => 'Tarjeta terminada en 4242',
                'notes' => 'Copia de seguridad del servidor principal.',
            ],
            [
                'name' => 'Cloudflare Pro',
                'provider' => 'cloudflare',
                'category' => 'hosting',
                'period' => BillingPeriod::MONTHLY,
                'prices' => [
                    ['monthsAgo' => 4, 'amount' => 2000],
                ],
                'nextChargeInDays' => 5,
                'notes' => 'CDN, DNS y protección contra DDoS.',
            ],
            [
                'name' => 'AWS Lightsail (servidor en Oregon)',
                'provider' => 'aws',
                'category' => 'hosting',
                'period' => BillingPeriod::MONTHLY,
                'currency' => Currency::USD,
                'prices' => [
                    ['monthsAgo' => 3, 'amount' => 1000],
                ],
                'nextChargeInDays' => 18,
                'plan' => 'Linux/Unix 2 GB',
                'notes' => 'Servidor en Estados Unidos: se paga en dólares y no se suma a los euros.',
            ],
            [
                'name' => 'Google Workspace Business Starter',
                'provider' => 'google',
                'category' => 'software',
                'period' => BillingPeriod::MONTHLY,
                'prices' => [
                    ['monthsAgo' => 4, 'amount' => 1440],
                ],
                'renewalInDays' => 25,
                'noticeDays' => 30,
                'plan' => 'Business Starter',
                'paymentMethod' => 'Facturación de Google',
                'notes' => 'Correo y calendario para las cinco personas del equipo.',
            ],
            [
                'name' => 'Adobe Creative Cloud',
                'provider' => 'adobe',
                'category' => 'software',
                'period' => BillingPeriod::MONTHLY,
                'prices' => [
                    ['monthsAgo' => 4, 'amount' => 5999],
                ],
                'nextChargeInDays' => 20,
                'notes' => 'Diseño y vídeo para las piezas de la campaña.',
            ],
            [
                'name' => 'Adobe Lightroom',
                'provider' => 'adobe',
                'category' => 'software',
                'period' => BillingPeriod::MONTHLY,
                'prices' => [
                    ['monthsAgo' => 4, 'amount' => 1199],
                ],
                'status' => 'paused',
                'notes' => 'Pausado mientras se migra el catálogo de fotos.',
            ],
            [
                'name' => 'Dropbox Plus',
                'provider' => 'dropbox',
                'category' => 'software',
                'period' => BillingPeriod::MONTHLY,
                'prices' => [
                    ['monthsAgo' => 4, 'amount' => 1199],
                ],
                'status' => 'cancelled',
                'notes' => 'Cancelado en favor del almacenamiento de Google.',
            ],
            [
                'name' => 'Movistar Fibra + Móvil',
                'provider' => 'movistar',
                'category' => 'telecomunicaciones',
                'period' => BillingPeriod::MONTHLY,
                'prices' => [
                    ['monthsAgo' => 4, 'amount' => 4990],
                ],
                'renewalInDays' => 40,
                'noticeDays' => 30,
                'paymentMethod' => 'Domiciliación bancaria',
                'notes' => 'Fibra de 1 Gbps y dos líneas móviles.',
            ],
            [
                'name' => 'Mapfre Salud Confort',
                'provider' => 'mapfre',
                'category' => 'seguros',
                'period' => BillingPeriod::MONTHLY,
                'prices' => [
                    ['monthsAgo' => 5, 'amount' => 4250],
                ],
                'nextChargeInDays' => 16,
                'notes' => 'Póliza de salud para las dos personas socias.',
            ],
            [
                'name' => 'Repsol Gasóleo',
                'provider' => 'repsol',
                'category' => 'suministros',
                'period' => BillingPeriod::MONTHLY,
                'prices' => [
                    ['monthsAgo' => 3, 'amount' => 8820],
                ],
                'nextChargeInDays' => 2,
                'paymentMethod' => 'Tarjeta de la empresa',
                'notes' => 'Gasóleo del vehículo de reparto.',
            ],
            [
                'name' => 'Endesa Suministro',
                'provider' => 'endesa',
                'category' => 'suministros',
                'period' => BillingPeriod::MONTHLY,
                'prices' => [
                    ['monthsAgo' => 3, 'amount' => 6430],
                ],
                'nextChargeInDays' => 27,
                'notes' => 'Electricidad del local.',
            ],
            [
                'name' => 'Meta Ads',
                'provider' => 'meta',
                'category' => 'marketing',
                'period' => BillingPeriod::MONTHLY,
                'prices' => [
                    ['monthsAgo' => 3, 'amount' => 25000],
                ],
                'nextChargeInDays' => 3,
                'notes' => 'Campañas de captación en Instagram y Facebook.',
            ],
            [
                'name' => 'Coursera Plus',
                'provider' => 'coursera',
                'category' => 'formacion',
                'period' => BillingPeriod::ANNUAL,
                'prices' => [
                    ['monthsAgo' => 2, 'amount' => 5999],
                ],
                'renewalInDays' => 50,
                'noticeDays' => 15,
                'notes' => 'Formación del equipo durante un año.',
            ],
            [
                'name' => 'Netflix Premium',
                'provider' => 'netflix',
                'category' => 'otros',
                'period' => BillingPeriod::MONTHLY,
                'prices' => [
                    ['monthsAgo' => 4, 'amount' => 1299],
                    ['monthsAgo' => 3, 'amount' => 1549],
                ],
                'nextChargeInDays' => 18,
                'notes' => 'Suscripción de la sala de espera.',
            ],
            [
                'name' => 'Spotify Family',
                'provider' => 'spotify',
                'category' => 'otros',
                'period' => BillingPeriod::MONTHLY,
                'prices' => [
                    ['monthsAgo' => 4, 'amount' => 1999],
                ],
                'nextChargeInDays' => 22,
            ],
            [
                'name' => 'Coworking Flexible',
                'provider' => null,
                'category' => 'servicios-profesionales',
                'period' => BillingPeriod::MONTHLY,
                'prices' => [
                    ['monthsAgo' => 3, 'amount' => 18000],
                ],
                'nextChargeInDays' => -14,
                'notes' => 'Puestos de trabajo por dias. El cobro de este mes todavia no ha llegado.',
            ],
            [
                'name' => 'Licencia Antivirus Corporativo',
                'provider' => null,
                'category' => 'software',
                'period' => BillingPeriod::ANNUAL,
                'prices' => [
                    ['monthsAgo' => 13, 'amount' => 12000],
                ],
                'notes' => 'Renovación anual del antivirus de los Portable.',
            ],
        ];
    }
}
