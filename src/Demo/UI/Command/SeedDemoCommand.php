<?php

declare(strict_types=1);

namespace App\Demo\UI\Command;

use App\Demo\Application\SeedDemoTenant;

use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Siembra la cuenta y el tenant de demostración.
 *
 * ## Qué hace
 *
 * Crea la cuenta de demostración —si no existe—, le deja el plan que permite
 * ver el producto entero, le marca la guía de bienvenida como vista y siembra
 * un inventario de servicios con historia de precios, propuestas de cambio y
 * avisos. Al final imprime las credenciales, que es lo que se quiere ver
 * después de ejecutarlo.
 *
 * ## Es idempotente
 *
 * Se puede repetir sin miedo: no duplica servicios ni avisos, y la contraseña
 * de la cuenta se reescribe para que el estado final sea siempre el mismo.
 *
 * ## Uso
 *
 *     bin/console app:demo:seed
 *
 * En producción hace falta `--force`, para que nadie siembre por accidente
 * una cuenta de entrar en una instalación real.
 */
#[AsCommand(
    name: 'app:demo:seed',
    description: 'Siembra la cuenta de demostración con datos de ejemplo.',
)]
final class SeedDemoCommand extends Command
{
    public function __construct(
        private readonly SeedDemoTenant $seed,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Siembra tambien fuera del entorno de desarrollo.')
            ->setHelp(<<<'HELP'
                Siembra la cuenta de demostración con un inventario de ejemplo.

                  bin/console app:demo:seed

                La operación es idempotente: se puede repetir sin duplicar nada.
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('dev' !== $this->environment && !$input->getOption('force')) {
            $io->error('Este comando solo se ejecuta en desarrollo. Usa --force si sabes lo que haces.');

            return Command::FAILURE;
        }

        $io->title('Siembra de la demostración');

        try {
            $result = ($this->seed)();
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Cuenta' => $result->email],
            ['Contraseña' => $result->password],
            ['Organización' => $result->organizationName],
            ['Servicios creados' => (string) $result->servicesCreated],
            ['Servicios ya existentes' => (string) $result->servicesSkipped],
            ['Propuestas de cambio' => (string) $result->proposals],
            ['Avisos nuevos' => (string) $result->alertsCreated],
            ['Llamadas de IA' => (string) $result->aiCalls],
        );

        $io->success(sprintf(
            'Demostración lista. Entra con %s / %s',
            $result->email,
            $result->password,
        ));

        return Command::SUCCESS;
    }
}
