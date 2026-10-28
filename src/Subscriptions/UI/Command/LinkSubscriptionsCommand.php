<?php

declare(strict_types=1);

namespace App\Subscriptions\UI\Command;

use App\Subscriptions\Application\LinkSubscriptionsToPlans;

use function array_map;
use function count;
use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rellena el plan de las suscripciones creadas antes de que existiera el
 * catálogo (Fase 22).
 *
 * ## Por qué esto no es una migración
 *
 * Los identificadores de los planes los genera la base de datos al sembrar el
 * catálogo, así que no se pueden escribir en una migración que se aplica antes
 * de que exista nada. Además, emparejar por nombre es una heurística: se puede
 * volver a lanzar sin efectos (solo mira las que siguen sin plan) y admite
 * `--dry-run` para revisar el resultado antes de tocar datos.
 *
 * ## Qué no hace
 *
 * No renombra la suscripción. Su nombre está congelado desde el alta (D-79):
 * enlazarla con un plan no cambia lo que el cliente contrató.
 */
#[AsCommand(
    name: 'app:catalog:link-subscriptions',
    description: 'Enlaza con su plan las suscripciones que aún no lo tienen.',
)]
final class LinkSubscriptionsCommand extends Command
{
    public function __construct(
        private readonly LinkSubscriptionsToPlans $linkSubscriptions,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Enseña lo que enlazaría sin escribir nada.')
            ->setHelp(<<<'HELP'
                Empareja por nombre la suscripción con el plan del catálogo. Solo enlaza
                cuando hay **exactamente un** candidato: si dos planes encajan, deja la
                fila pendiente para elegirla a mano en su ficha.

                  bin/console app:catalog:link-subscriptions --dry-run
                  bin/console app:catalog:link-subscriptions
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $linked = ($this->linkSubscriptions)($dryRun);

        if ([] === $linked) {
            $io->success('No hay suscripciones sin plan que encajen con el catálogo.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Suscripción', 'Plan'],
            array_map(static fn (array $row): array => [$row['name'], $row['plan']], $linked),
        );

        if ($dryRun) {
            $io->note(sprintf('Simulación: %d enlace(s) no aplicados.', count($linked)));

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d suscripción(es) enlazadas con su plan.', count($linked)));

        return Command::SUCCESS;
    }
}
