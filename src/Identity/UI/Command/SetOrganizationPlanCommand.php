<?php

declare(strict_types=1);

namespace App\Identity\UI\Command;

use App\Identity\Application\ChangeOrganizationPlan;
use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Enum\OrganizationPlan;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Entitlements\EntitlementSettings;

use function implode;
use function sprintf;
use function strtolower;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Cambia el plan de una organización (Fase 17).
 *
 * ## Por qué un comando y no un botón
 *
 * Cambiar de plan es una decisión comercial: alguien paga y alguien lo
 * cambia. En esta versión el cobro no está integrado, así que el cambio lo
 * hace el operador de la instalación cuando confirma el pago. Un botón en la
 * aplicación que no cobra nada sería regalar el plan de pago.
 *
 * ## Uso
 *
 *     bin/console app:plan:set mi-empresa pro
 *     bin/console app:plan:set 0192f0c4-... free
 *
 * La organización se puede localizar por su **slug** o por su **id**.
 */
#[AsCommand(
    name: 'app:plan:set',
    description: 'Cambia el plan de una organización.',
)]
final class SetOrganizationPlanCommand extends Command
{
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly ChangeOrganizationPlan $changePlan,
        private readonly EntitlementSettings $entitlements,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('organization', InputArgument::REQUIRED, 'Slug o id de la organización.')
            ->addArgument('plan', InputArgument::REQUIRED, 'Plan de destino (free, pro).')
            ->setHelp(sprintf(
                <<<'HELP'
                    Cambia el plan de una organización y lo deja anotado en el libro de auditoría.

                      bin/console app:plan:set mi-empresa pro

                    Planes definidos: %s
                    HELP,
                implode(', ', $this->entitlements->planNames()),
            ))
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->text(sprintf('Planes definidos: %s', implode(', ', $this->entitlements->planNames())));

        $reference = (string) $input->getArgument('organization');
        $organization = $this->find($reference);

        if (null === $organization) {
            $io->error(sprintf('No se ha encontrado ninguna organización con el identificador "%s".', $reference));

            return Command::FAILURE;
        }

        $name = strtolower((string) $input->getArgument('plan'));
        $plan = OrganizationPlan::tryFrom($name);

        if (null === $plan) {
            $io->error(sprintf('El plan "%s" no existe. Los planes son: %s.', $name, implode(', ', $this->entitlements->planNames())));

            return Command::FAILURE;
        }

        $previous = $organization->getPlan();

        try {
            ($this->changePlan)($organization, $plan, $this->entitlements->planNames());
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'La organización "%s" pasa del plan "%s" al plan "%s".',
            $organization->getName(),
            $previous->value,
            $plan->value,
        ));

        return Command::SUCCESS;
    }

    /**
     * Busca la organización por slug o por id.
     */
    private function find(string $reference): ?Organization
    {
        $organization = $this->organizations->findBySlug($reference);

        if (null !== $organization) {
            return $organization;
        }

        if (!Uuid::isValid($reference)) {
            return null;
        }

        return $this->organizations->find(Uuid::fromString($reference));
    }
}
