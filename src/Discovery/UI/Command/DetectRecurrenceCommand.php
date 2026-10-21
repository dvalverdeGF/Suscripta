<?php

declare(strict_types=1);

namespace App\Discovery\UI\Command;

use App\Discovery\Application\DetectRecurrence;
use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Services\Domain\Repository\ServiceFilters;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
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
 * Recalcula la periodicidad y el próximo cobro de los servicios a partir de su
 * historial de facturas.
 *
 * Es el trabajo periódico que mantiene al día la fecha de próximo cobro: el
 * usuario no escribe la periodicidad, la deduce el sistema a partir de lo que ha
 * cobrado. Los servicios con cobro irregular se dejan como estaban: es
 * preferible no proponer fecha antes que proponer una equivocada.
 *
 * Es idempotente: ejecutarlo dos veces seguidas no cambia nada la segunda vez.
 */
#[AsCommand(
    name: 'app:recurrence:detect',
    description: 'Detecta la periodicidad de los servicios a partir de sus facturas y recalcula el próximo cobro.',
)]
final class DetectRecurrenceCommand extends Command
{
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly ServiceRepositoryInterface $services,
        private readonly DetectRecurrence $detectRecurrence,
        private readonly TenantContext $tenantContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'Identificador de la organización. Si se omite, se procesan todas.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de servicios por organización.', '200')
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
        $failures = 0;

        foreach ($organizations as $organization) {
            $io->section($organization->getName());

            try {
                $this->tenantContext->runAs($organization->getId(), function () use ($io, $limit): void {
                    $this->processOrganization($io, $limit);
                });
            } catch (Throwable $e) {
                ++$failures;
                $io->error(sprintf('No se ha podido detectar la periodicidad: %s', $e->getMessage()));
            }
        }

        if ($failures > 0) {
            $io->warning(sprintf('%d organizaciones han fallado.', $failures));

            return Command::FAILURE;
        }

        $io->success('Periodicidad detectada.');

        return Command::SUCCESS;
    }

    private function processOrganization(SymfonyStyle $io, int $limit): void
    {
        $detected = 0;
        $irregular = 0;
        $services = $this->services->findForOrganization(ServiceFilters::none());

        foreach ($services as $service) {
            if ($detected + $irregular >= $limit) {
                break;
            }

            $result = ($this->detectRecurrence)($service->getId());

            if ($result->isRegular) {
                ++$detected;
                $io->text(sprintf(
                    '%s: %s (confianza %d %%).',
                    $service->getName(),
                    $result->period?->label() ?? 'sin periodicidad',
                    (int) round($result->confidence * 100),
                ));

                continue;
            }

            ++$irregular;
            $io->text(sprintf('%s: cobro irregular, no se propone próximo cobro.', $service->getName()));
        }

        $io->text(sprintf('Servicios con periodicidad: %d. Servicios irregulares: %d.', $detected, $irregular));
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
