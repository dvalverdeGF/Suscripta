<?php

declare(strict_types=1);

namespace App\Billing\UI\Command;

use App\Billing\Application\ReconcileSubscriptions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Concilia la facturación con la pasarela (Fase 24).
 *
 * Se ejecuta desde el cron, como el resto de comandos `app:*` (ver
 * `docs/operations.md` §8). No usa el componente Scheduler porque el proyecto
 * no lo tiene instalado y añadirlo solo para esto obligaría a un worker más.
 *
 * El comando **no falla** cuando la pasarela no está configurada: en un
 * despliegue sin cobros todavía eso es lo normal, y un cron en rojo todas las
 * noches deja de mirarse a la semana.
 */
#[AsCommand(
    name: 'app:billing:reconcile',
    description: 'Pone al día las suscripciones y facturas de Suscripta contra la pasarela de pago.',
)]
final class ReconcileBillingCommand extends Command
{
    public function __construct(private readonly ReconcileSubscriptions $reconcile)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = ($this->reconcile)();

        if (0 === $result['checked']) {
            $io->note('No hay ninguna suscripción cobrada por la pasarela que conciliar.');

            return Command::SUCCESS;
        }

        $io->success(ReconcileSubscriptions::describe($result));

        if ($result['missing'] > 0) {
            $io->warning('Hay suscripciones locales que la pasarela no conoce. No se han cancelado: revísalas en el panel de Stripe.');
        }

        return Command::SUCCESS;
    }
}
