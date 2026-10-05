<?php

declare(strict_types=1);

namespace App\Notifications\UI\Command;

use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Notifications\Application\DispatchNotifications;
use App\Notifications\Application\GenerateAlerts;
use App\Shared\Application\TenantContext;

use function is_string;
use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Genera y entrega los avisos de todas las organizaciones.
 *
 * Es el trabajo periódico del producto: sin él no hay avisos. Se ejecuta sin
 * argumentos desde el planificador y recorre todas las organizaciones, cada una
 * dentro de su propio contexto de tenant para que el filtro de Doctrine haga su
 * trabajo y ninguna vea datos de otra.
 *
 * Es idempotente: ejecutarlo dos veces seguidas no duplica avisos ni correos.
 */
#[AsCommand(
    name: 'app:alerts:generate',
    description: 'Genera los avisos de renovaciones y cobros y los entrega por los canales activos.',
)]
final class GenerateAlertsCommand extends Command
{
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly GenerateAlerts $generateAlerts,
        private readonly DispatchNotifications $dispatchNotifications,
        private readonly TenantContext $tenantContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'Identificador de la organización. Si se omite, se procesan todas.')
            ->addOption('no-notify', null, InputOption::VALUE_NONE, 'Solo genera los avisos; no entrega notificaciones.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de avisos abiertos a entregar por organización.', '50')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $organizations = $this->resolveOrganizations($input->getOption('organization'));

        if ([] === $organizations) {
            $io->warning('No hay ninguna organización que procesar.');

            return Command::SUCCESS;
        }

        $notify = !$input->getOption('no-notify');
        $limit = (int) $input->getOption('limit');
        $failures = 0;

        foreach ($organizations as $organization) {
            $io->section($organization->getName());

            try {
                $this->tenantContext->runAs($organization->getId(), function () use ($io, $notify, $limit): void {
                    $result = ($this->generateAlerts)();

                    $io->text(sprintf(
                        'Avisos: %d nuevos, %d resueltos, %d sin cambios. Abiertos: %d.',
                        $result->created,
                        $result->resolved,
                        $result->unchanged,
                        $result->open,
                    ));

                    if (!$notify) {
                        return;
                    }

                    $dispatch = ($this->dispatchNotifications)($limit);

                    $io->text(sprintf(
                        'Notificaciones: %d enviadas, %d omitidas, %d fallidas, %d ya entregadas.',
                        $dispatch->sent,
                        $dispatch->skipped,
                        $dispatch->failed,
                        $dispatch->alreadyDelivered,
                    ));
                });
            } catch (Throwable $e) {
                ++$failures;
                $io->error(sprintf('No se han podido generar los avisos: %s', $e->getMessage()));
            }
        }

        if ($failures > 0) {
            $io->warning(sprintf('%d organizaciones han fallado.', $failures));

            return Command::FAILURE;
        }

        $io->success('Avisos generados.');

        return Command::SUCCESS;
    }

    /**
     * @return list<Organization>
     */
    private function resolveOrganizations(mixed $identifier): array
    {
        if (is_string($identifier) && '' !== $identifier) {
            $organization = $this->organizations->find(Uuid::fromString($identifier));

            return null === $organization ? [] : [$organization];
        }

        return $this->organizations->findAll();
    }
}
