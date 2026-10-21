<?php

declare(strict_types=1);

namespace App\Discovery\UI\Command;

use App\Discovery\Application\DiscoverySettings;
use App\Discovery\Application\ExpireStaleDiscoveries;
use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
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
 * Caduca las propuestas de la bandeja que llevan demasiado tiempo sin que el
 * usuario decida nada (ARCHITECTURE.md §13.11).
 *
 * Es el counterpart del comando de detección: aquel mantiene vivo el
 * inventario, éste mantiene limpia la bandeja. Los dos son idempotentes, que
 * es lo que permite programarlos sin miedo.
 */
#[AsCommand(
    name: 'app:discoveries:expire',
    description: 'Caduca las propuestas de la bandeja que llevan demasiado tiempo sin decidir.',
)]
final class ExpireDiscoveriesCommand extends Command
{
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly ExpireStaleDiscoveries $expireStaleDiscoveries,
        private readonly DiscoverySettings $settings,
        private readonly TenantContext $tenantContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'Identificador de la organización. Si se omite, se procesan todas.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de propuestas por organización.', '200')
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

        $limit = (int) $input->getOption('limit');
        $total = 0;
        $failures = 0;

        foreach ($organizations as $organization) {
            $io->section($organization->getName());

            try {
                $expired = $this->tenantContext->runAs(
                    $organization->getId(),
                    fn (): int => ($this->expireStaleDiscoveries)($limit),
                );

                $total += $expired;
                $io->text(sprintf('Propuestas caducadas: %d.', $expired));
            } catch (Throwable $e) {
                ++$failures;
                $io->error(sprintf('No se ha podido caducar las propuestas: %s', $e->getMessage()));
            }
        }

        $io->text(sprintf(
            'Caducadas %d propuestas con una ventana de %d días.',
            $total,
            $this->settings->expiryDays,
        ));

        if ($failures > 0) {
            $io->warning(sprintf('%d organizaciones han fallado.', $failures));

            return Command::FAILURE;
        }

        $io->success('Bandeja de propuestas depurada.');

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
