<?php

declare(strict_types=1);

namespace App\Subscriptions\UI\Command;

use App\Identity\Domain\Enum\OrganizationPlan;
use App\Shared\Domain\ValueObject\BillingPeriod;
use App\Shared\Domain\ValueObject\Currency;
use App\Shared\Domain\ValueObject\Money;
use App\Subscriptions\Application\AddPlanFeature;
use App\Subscriptions\Application\CreatePlan;
use App\Subscriptions\Application\Dto\PlanInput;
use App\Subscriptions\Application\Dto\PlanPriceInput;
use App\Subscriptions\Application\PublishPlanPrice;
use App\Subscriptions\Domain\Repository\PlanRepositoryInterface;

use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Siembra el catálogo comercial con una ficha por cada plan de la aplicación.
 *
 * ## Para qué
 *
 * El catálogo nace vacío y una instalación recién desplegada necesita algo que
 * enseñar: sin ficha no hay nombre comercial, sin precio no se puede contratar
 * y sin características la página de precios está en blanco. Esto deja el punto
 * de partida listo en un comando.
 *
 * ## Qué es de mentira
 *
 * Los **precios** son de ejemplo y se cambian desde el backoffice (o con las
 * opciones de abajo): el precio real lo decide el negocio, no el código. Las
 * **características** son texto de marketing, también editable.
 *
 * Los **límites** no se siembran: viven en `entitlements.yaml` y el catálogo
 * solo los lee (D-64). Sembrarlos aquí crearía una segunda copia que se
 * desincroniza el día que alguien suba un tope.
 *
 * ## Se puede lanzar dos veces
 *
 * Si un plan ya tiene ficha, **no se toca**: ni el nombre, ni el precio, ni las
 * características que el operador haya escrito. Solo se crean las fichas que
 * faltan. Un comando de siembra que sobrescribe lo que hay es una forma
 * silenciosa de perder trabajo.
 */
#[AsCommand(
    name: 'app:catalog:seed',
    description: 'Crea la ficha comercial de los planes que aún no la tienen.',
)]
final class SeedCatalogCommand extends Command
{
    /**
     * Nombre comercial, gancho, descripción y características por defecto.
     *
     * @var array<string, array{name: string, tagline: string, description: string, highlighted: bool, features: list<array{string, bool}>}>
     */
    private const array DEFAULTS = [
        'free' => [
            'name' => 'Gratis',
            'tagline' => 'Para saber qué estás pagando',
            'description' => 'El punto de partida: descubre tus suscripciones y recibe avisos antes de que se renueven.',
            'highlighted' => false,
            'features' => [
                ['Detección automática desde tu correo', true],
                ['Avisos antes de cada renovación', true],
                ['Inventario de servicios y facturas', true],
                ['Inteligencia artificial', false],
            ],
        ],
        'pro' => [
            'name' => 'Profesional',
            'tagline' => 'Para quien vive de sus suscripciones',
            'description' => 'Todo lo del plan gratuito, sin techo de servicios y con inteligencia artificial para leer las facturas.',
            'highlighted' => true,
            'features' => [
                ['Servicios sin límite', true],
                ['Lectura automática de facturas con IA', true],
                ['Presupuesto mensual de IA incluido', true],
                ['Exportación de datos', true],
            ],
        ],
    ];

    public function __construct(
        private readonly PlanRepositoryInterface $plans,
        private readonly CreatePlan $createPlan,
        private readonly PublishPlanPrice $publishPlanPrice,
        private readonly AddPlanFeature $addPlanFeature,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('currency', null, InputOption::VALUE_REQUIRED, 'Moneda de los precios de ejemplo.', Currency::EUR->value)
            ->addOption('free-price', null, InputOption::VALUE_REQUIRED, 'Precio mensual del plan gratuito.', '0.00')
            ->addOption('pro-price', null, InputOption::VALUE_REQUIRED, 'Precio mensual del plan de pago.', '19.00')
            ->addOption('pro-annual', null, InputOption::VALUE_REQUIRED, 'Precio anual del plan de pago (vacío = no crear precio anual).', '182.40')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que haría sin escribir nada.')
            ->setHelp(<<<'HELP'
                Crea la ficha comercial de los planes que aún no la tienen. Los precios
                son de ejemplo y se cambian después desde el catálogo del backoffice.

                  bin/console app:catalog:seed
                  bin/console app:catalog:seed --pro-price=29.00 --pro-annual=278.40
                  bin/console app:catalog:seed --dry-run
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $currency = Currency::tryFrom((string) $input->getOption('currency'));

        if (null === $currency) {
            $io->error(sprintf('La moneda "%s" no está soportada.', (string) $input->getOption('currency')));

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $created = 0;

        foreach (OrganizationPlan::cases() as $position => $code) {
            if (null !== $this->plans->findByCode($code)) {
                $io->text(sprintf('· %s: ya tiene ficha, no se toca.', $code->label()));

                continue;
            }

            $defaults = self::DEFAULTS[$code->value] ?? [
                'name' => $code->label(),
                'tagline' => '',
                'description' => '',
                'highlighted' => false,
                'features' => [],
            ];

            if ($dryRun) {
                $io->text(sprintf('· %s: se crearía «%s».', $code->label(), $defaults['name']));

                continue;
            }

            $plan = ($this->createPlan)(new PlanInput(
                code: $code,
                name: $defaults['name'],
                tagline: '' === $defaults['tagline'] ? null : $defaults['tagline'],
                description: '' === $defaults['description'] ? null : $defaults['description'],
                isActive: true,
                isHighlighted: $defaults['highlighted'],
                position: $position,
            ));

            foreach ($this->pricesFor($code, $input, $currency) as $price) {
                ($this->publishPlanPrice)($plan, $price);
            }

            foreach ($defaults['features'] as [$label, $included]) {
                ($this->addPlanFeature)($plan, $label, $included);
            }

            ++$created;
            $io->text(sprintf('· %s: «%s» creado.', $code->label(), $plan->getName()));
        }

        if ($dryRun) {
            $io->note('Simulación: no se ha escrito nada.');

            return Command::SUCCESS;
        }

        if (0 === $created) {
            $io->success('El catálogo ya estaba completo.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Catálogo sembrado: %d plan(es) nuevo(s). Revisa los precios antes de publicar.', $created));

        return Command::SUCCESS;
    }

    /**
     * Precios de ejemplo de un plan.
     *
     * @return list<PlanPriceInput>
     */
    private function pricesFor(OrganizationPlan $code, InputInterface $input, Currency $currency): array
    {
        $prices = [new PlanPriceInput(
            amount: Money::fromDecimalString((string) $input->getOption('free' === $code->value ? 'free-price' : 'pro-price'), $currency),
            billingPeriod: BillingPeriod::MONTHLY,
        )];

        if ('free' === $code->value) {
            return $prices;
        }

        $annual = (string) $input->getOption('pro-annual');

        if ('' !== $annual) {
            $prices[] = new PlanPriceInput(
                amount: Money::fromDecimalString($annual, $currency),
                billingPeriod: BillingPeriod::ANNUAL,
            );
        }

        return $prices;
    }
}
