<?php

declare(strict_types=1);

namespace App\Identity\UI\Command;

use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Entitlements\EntitlementSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lista las organizaciones y el plan que tiene cada una (Fase 17).
 *
 * Es el comando de apoyo del `app:plan:set`: uno busca la organización
 * (por slug o por id) y el otro la cambia. Ver las dos juntas evita tener
 * que recordar el slug de memoria.
 */
#[AsCommand(
    name: 'app:plan:list',
    description: 'Lista las organizaciones y su plan.',
)]
final class ListOrganizationPlansCommand extends Command
{
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly EntitlementSettings $entitlements,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $organizations = $this->organizations->findAll();

        if ([] === $organizations) {
            $io->info('No hay ninguna organización.');

            return Command::SUCCESS;
        }

        $rows = [];

        foreach ($organizations as $organization) {
            $rows[] = [
                $organization->getSlug(),
                $organization->getName(),
                $organization->getPlan()->value,
                $this->entitlements->planLabel($organization->getPlan()),
            ];
        }

        $io->table(['Slug', 'Nombre', 'Plan', 'Nombre del plan'], $rows);

        return Command::SUCCESS;
    }
}
