<?php

declare(strict_types=1);

namespace App\Privacy\UI\Command;

use App\Privacy\Application\PurgeExpiredData;

use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Aplica la política de retención de datos.
 *
 * Se ejecuta periódicamente (cron) y borra lo que ha caducado: correo
 * antiguo, eventos de procesamiento, documentos borrados hace más de un mes,
 * propuestas descartadas, alertas resueltas, uso de IA, conocimiento aprendido
 * sin usar y entradas de la caché.
 *
 * La purga es **global**, no por organización: los plazos son de política, y
 * cada registro lleva su propia organización, así que el filtro de
 * inquilino no aplica aquí. Es el mismo criterio que usa el resto de los
 * comandos de sistema.
 *
 * Es idempotente: ejecutarlo dos veces seguidas no borra nada la segunda
 * vez, porque lo que se borró ya no está.
 */
#[AsCommand(
    name: 'app:privacy:purge',
    description: 'Purga los datos que han superado la política de retención.',
)]
final class PurgeExpiredDataCommand extends Command
{
    public function __construct(
        private readonly PurgeExpiredData $purge,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $report = ($this->purge)();

        if ($report->isEmpty()) {
            $io->success('No había nada que purgar.');

            return Command::SUCCESS;
        }

        $rows = [];

        foreach ($report->removedByCategory as $category => $removed) {
            $rows[] = [$category, $removed];
        }

        $io->table(['Categoría', 'Elementos purgados'], $rows);
        $io->success(sprintf('Se han purgado %d elementos.', $report->total()));

        return Command::SUCCESS;
    }
}
