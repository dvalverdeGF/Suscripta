<?php

declare(strict_types=1);

namespace App\Processing\UI\Command;

use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Mailbox\Domain\Enum\MessageProcessingState;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Processing\Domain\Repository\ExtractionCacheRepositoryInterface;
use App\Shared\Application\TenantContext;

use function array_sum;
use function is_string;
use function number_format;
use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Muestra el embudo del pipeline: qué ha pasado con cada correo leído.
 *
 * Existe porque el nivel 2 es una **decisión de coste**, y una decisión de coste
 * que no se mide se degrada sola. Si el porcentaje de descartes baja, el
 * pipeline está analizando más de lo que debería y la factura de IA sube sin
 * que nadie lo note (D-36).
 *
 * Es de solo lectura: no modifica nada.
 */
#[AsCommand(
    name: 'app:mail:stats',
    description: 'Resume el embudo del pipeline de correo: estados, descartes y uso de la caché de extracción.',
)]
final class MailboxStatsCommand extends Command
{
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly EmailMessageRepositoryInterface $messages,
        private readonly ExtractionCacheRepositoryInterface $extractionCache,
        private readonly TenantContext $tenantContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'Identificador de la organización. Si se omite, se muestran todas.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $requested = $input->getOption('organization');

        if (null !== $requested && !is_string($requested)) {
            $io->error('El identificador de la organización no es válido.');

            return Command::INVALID;
        }

        $organizations = $this->organizations->findAll();

        if ([] === $organizations) {
            $io->warning('No hay ninguna organización que analizar.');

            return Command::SUCCESS;
        }

        $rows = [];

        foreach ($organizations as $organization) {
            if (null !== $requested && $organization->getId()->toRfc4122() !== $requested) {
                continue;
            }

            $rows[] = $this->tenantContext->runAs(
                $organization->getId(),
                fn (): array => $this->summarize($organization->getName()),
            );
        }

        if ([] === $rows) {
            $io->warning('No hemos encontrado esa organización.');

            return Command::INVALID;
        }

        $io->table(
            ['Organización', 'Leídos', 'Descartados', 'Descartes', 'Candidatos', 'Descubrimientos', 'En revisión', 'Fallidos', 'Caché'],
            $rows,
        );

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function summarize(string $organizationName): array
    {
        $counts = $this->messages->countByState();
        $total = array_sum($counts);

        $ignored = $counts[MessageProcessingState::IGNORED->value] ?? 0;
        $candidates = $counts[MessageProcessingState::CANDIDATE->value] ?? 0;
        $discoveries = $counts[MessageProcessingState::DISCOVERY->value] ?? 0;
        $review = $counts[MessageProcessingState::REQUIRES_REVIEW->value] ?? 0;
        $failed = $counts[MessageProcessingState::FAILED->value] ?? 0;

        return [
            $organizationName,
            (string) $total,
            (string) $ignored,
            $this->percentage($ignored, $total),
            (string) $candidates,
            (string) $discoveries,
            (string) $review,
            (string) $failed,
            (string) $this->extractionCache->countForOrganization(),
        ];
    }

    private function percentage(int $part, int $total): string
    {
        if (0 === $total) {
            return '—';
        }

        return sprintf('%s %%', number_format($part * 100 / $total, 1, ',', '.'));
    }
}
