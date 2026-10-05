<?php

declare(strict_types=1);

namespace App\Catalog\UI\Command;

use App\Catalog\Application\SeedCatalog;

use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:catalog:seed',
    description: 'Siembra el catálogo global de categorías y proveedores (idempotente).',
)]
final class SeedCatalogCommand extends Command
{
    public function __construct(private readonly SeedCatalog $seedCatalog)
    {
        parent::__construct();
    }

    protected function execute(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = ($this->seedCatalog)();

        if (0 === $result['categories'] && 0 === $result['providers'] && 0 === $result['identities']) {
            $io->success('El catálogo ya estaba al día.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Catálogo sembrado: %d categorías, %d proveedores, %d identidades.',
            $result['categories'],
            $result['providers'],
            $result['identities'],
        ));

        return Command::SUCCESS;
    }
}
