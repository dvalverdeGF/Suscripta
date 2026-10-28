<?php

declare(strict_types=1);

namespace App\Webhooks\UI\Command;

use App\Webhooks\Application\AbandonStaleDeliveries;
use App\Webhooks\Application\Message\DeliverWebhookMessage;
use App\Webhooks\Domain\Repository\WebhookDeliveryRepositoryInterface;

use function count;
use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Mantenimiento de la cola de webhooks (Fase 20).
 *
 * Hace dos cosas que la cola por sí sola no garantiza:
 *
 * - **Reencolar** entregas pendientes cuya hora ya pasó y que no tienen mensaje
 *   en vuelo. Pasa cuando el worker se reinicia entre el fallo y el reintento:
 *   la fila dice «intentar a las 12:05» y a las 12:05 no hay nadie mirando.
 * - **Abandonar** las que llevan una semana sin conseguirse.
 *
 * Es idempotente: reencolar una entrega ya encolada solo provoca un intento que
 * encuentra la fila no pendiente y no hace nada.
 */
#[AsCommand(
    name: 'app:webhooks:maintain',
    description: 'Reencola las entregas de webhook vencidas y abandona las que ya no se reintentan.',
)]
final class MaintainWebhookDeliveriesCommand extends Command
{
    public function __construct(
        private readonly WebhookDeliveryRepositoryInterface $deliveries,
        private readonly AbandonStaleDeliveries $abandonStaleDeliveries,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de entregas a reencolar.', '200')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Solo cuenta, no encola ni abandona nada.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');

        $due = $this->deliveries->findDue($limit);

        if (!$dryRun) {
            foreach ($due as $delivery) {
                $this->messageBus->dispatch(
                    new DeliverWebhookMessage($delivery->getId()),
                    [new TransportNamesStamp(['webhooks'])],
                );
            }
        }

        $abandoned = $dryRun ? 0 : ($this->abandonStaleDeliveries)();

        $io->success(sprintf(
            '%s %d entregas vencidas y %s %d abandonadas.',
            $dryRun ? 'Se reencolarían' : 'Se han reencolado',
            count($due),
            $dryRun ? 'se abandonarían' : 'se han abandonado',
            $abandoned,
        ));

        return Command::SUCCESS;
    }
}
