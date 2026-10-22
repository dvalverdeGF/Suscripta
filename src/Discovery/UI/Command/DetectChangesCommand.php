<?php

declare(strict_types=1);

namespace App\Discovery\UI\Command;

use App\Discovery\Application\Change\DetectChanges;
use App\Discovery\Domain\Enum\DiscoveryType;
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
 * Barrido de detección de cambios (Fase 15).
 *
 * Recorre el inventario de cada organización y deja en la bandeja de revisión
 * las **sugerencias** que ha encontrado: posibles duplicados, cobros que no
 * han llegado y servicios sin actividad.
 *
 * No es un aviso: el comando no manda nada, deja propuestas para que el
 * usuario las revise. Y es **idempotente**: repetirlo no vuelve a proponer lo
 * que ya se propuso, así que se puede lanzar cada noche sin que la bandeja se
 * llene de lo mismo.
 */
#[AsCommand(
    name: 'app:changes:detect',
    description: 'Busca cambios en el inventario (duplicados, cobros que faltan, servicios sin actividad) y deja sugerencias para revisar.',
)]
final class DetectChangesCommand extends Command
{
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly DetectChanges $detectChanges,
        private readonly TenantContext $tenantContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'Identificador de la organización. Si se omite, se procesan todas.')
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

        $failures = 0;

        foreach ($organizations as $organization) {
            $io->section($organization->getName());

            try {
                $this->tenantContext->runAs($organization->getId(), function () use ($io): void {
                    $this->processOrganization($io);
                });
            } catch (Throwable $e) {
                ++$failures;
                $io->error(sprintf('No se ha podido hacer el barrido: %s', $e->getMessage()));
            }
        }

        if ($failures > 0) {
            $io->warning(sprintf('%d organizaciones han fallado.', $failures));

            return Command::FAILURE;
        }

        $io->success('Barrido de cambios completado.');

        return Command::SUCCESS;
    }

    private function processOrganization(SymfonyStyle $io): void
    {
        $result = ($this->detectChanges)();

        foreach ($result->proposals as $proposal) {
            $name = $proposal->getProposedData()['serviceName'] ?? $proposal->getProposedData()['providerName'] ?? 'servicio sin identificar';

            $io->text(sprintf(
                '%s: %s (fuerza de la señal %d %%).',
                $proposal->getType()->label(),
                $name,
                $proposal->getConfidenceScore(),
            ));
        }

        $io->text(sprintf(
            'Servicios mirados: %d. Sugerencias nuevas: %d (duplicados %d, cobros que faltan %d, sin actividad %d).',
            $result->servicesInspected,
            $result->total(),
            $result->countOf(DiscoveryType::DUPLICATE),
            $result->countOf(DiscoveryType::MISSING_CHARGE),
            $result->countOf(DiscoveryType::DORMANT),
        ));
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
