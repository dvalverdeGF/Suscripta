<?php

declare(strict_types=1);

namespace App\Processing\UI\Command;

use App\Identity\Domain\Entity\Organization;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Processing\Application\ReleaseDeferredMessages;
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
 * Suelta los correos que quedaron aplazados por falta de presupuesto de IA
 * (Fase 14, D-56).
 *
 * ## Por qué existe este comando
 *
 * Un correo aplazado (`DEFERRED`) no está pendiente: el pipeline ya lo terminó
 * con «no puedo continuar ahora». Por eso no se reencola solo en cada
 * sincronización, sino que hace falta un gesto explícito para devolverlo al
 * circuito.
 *
 * Ese gesto tiene dos formas:
 *
 * 1. El usuario sube el tope en el panel: se sueltan en el acto, porque ha
 *    tomado una decisión.
 * 2. Empieza un mes nuevo: se sueltan aquí, por comando, porque no hay nadie
 *    mirando la pantalla.
 *
 * El comando no se salta la comprobación del presupuesto a propósito: si sigue
 * agotado, soltar los correos solo los volvería a aplazar y gastaría una
 * llamada de IA inútil. Es la misma regla que aplica el servicio, vista desde
 * la línea de órdenes.
 *
 * Es idempotente: si no hay nada aplazado, no hace nada y termina bien.
 */
#[AsCommand(
    name: 'app:ai:release-deferred',
    description: 'Suelta los correos aplazados por presupuesto de IA y los devuelve al pipeline.',
)]
final class ReleaseDeferredMessagesCommand extends Command
{
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly ReleaseDeferredMessages $releaseDeferred,
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
                $released = $this->tenantContext->runAs(
                    $organization->getId(),
                    fn (): int => ($this->releaseDeferred)(),
                );

                $total += $released;
                $io->text(sprintf('Correos soltados: %d.', $released));
            } catch (Throwable $e) {
                ++$failures;
                $io->error(sprintf('No se han podido soltar los correos aplazados: %s', $e->getMessage()));
            }
        }

        $io->text(sprintf('Soltados %d correos aplazados.', $total));

        if ($failures > 0) {
            $io->warning(sprintf('%d organizaciones han fallado.', $failures));

            return Command::FAILURE;
        }

        $io->success('Correos aplazados devueltos al pipeline.');

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
