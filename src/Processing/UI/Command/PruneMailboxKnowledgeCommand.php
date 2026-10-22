<?php

declare(strict_types=1);

namespace App\Processing\UI\Command;

use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Processing\Application\Knowledge\KnowledgeSettings;
use App\Processing\Application\Knowledge\PruneMailboxKnowledge;
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
 * Poda el conocimiento aprendido de los buzones (ARCHITECTURE.md §13.12).
 *
 * El conocimiento crece con el uso, y no todo lo que se aprende sirve. La
 * poda solo elimina lo que **nunca se ha usado** y lleva tiempo guardado: un
 * patrón que no ha casado con ningún correo en tres meses no va a empezar a
 * casar solo.
 *
 * Lo que sí se usa no se toca, y lo que el usuario ya no quiere lo borra el
 * usuario. Automatizar ese borrado sería una sorpresa.
 */
#[AsCommand(
    name: 'app:knowledge:prune',
    description: 'Elimina el conocimiento aprendido que no se ha usado nunca y es antiguo.',
)]
final class PruneMailboxKnowledgeCommand extends Command
{
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly PruneMailboxKnowledge $pruneKnowledge,
        private readonly KnowledgeSettings $settings,
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

        $total = 0;
        $failures = 0;

        foreach ($organizations as $organization) {
            $io->section($organization->getName());

            try {
                $pruned = $this->tenantContext->runAs(
                    $organization->getId(),
                    fn (): int => ($this->pruneKnowledge)(),
                );

                $total += $pruned;
                $io->text(sprintf('Conocimiento eliminado: %d.', $pruned));
            } catch (Throwable $e) {
                ++$failures;
                $io->error(sprintf('No se ha podido podar el conocimiento: %s', $e->getMessage()));
            }
        }

        $io->text(sprintf(
            'Eliminadas %d entradas que no se habían usado en %d días.',
            $total,
            $this->settings->staleDays,
        ));

        if ($failures > 0) {
            $io->warning(sprintf('%d organizaciones han fallado.', $failures));

            return Command::FAILURE;
        }

        $io->success('Conocimiento de los buzones podado.');

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
