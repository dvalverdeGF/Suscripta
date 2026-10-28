<?php

declare(strict_types=1);

namespace App\Identity\UI\Command;

use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Identity\Domain\Security\Roles;

use function array_filter;
use function array_unique;
use function array_values;
use function in_array;
use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Concede o retira `ROLE_OPERATOR` a una cuenta (Fase 22).
 *
 * ## Por qué a mano y no con un botón
 *
 * Ser operador es poder cambiar el precio que ve todo el mundo. Eso no se pide
 * desde una pantalla: se concede desde la consola del servidor, donde ya hay
 * acceso a la base de datos de todos modos. Un botón que elevara privilegios
 * convertiría una cuenta comprometida en un cambio de precios.
 *
 * ## Uso
 *
 *     bin/console app:user:grant-operator persona@ejemplo.com
 *     bin/console app:user:grant-operator persona@ejemplo.com --revoke
 */
#[AsCommand(
    name: 'app:user:grant-operator',
    description: 'Concede o retira el rol de operador (acceso al catálogo comercial).',
)]
final class GrantOperatorCommand extends Command
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Correo de la cuenta.')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Retira el rol en lugar de concederlo.')
            ->setHelp(<<<'HELP'
                El rol de operador da acceso al catálogo comercial: los planes que se
                ofrecen y sus precios. Es global, no depende de ninguna organización.

                  bin/console app:user:grant-operator persona@ejemplo.com
                  bin/console app:user:grant-operator persona@ejemplo.com --revoke
                HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = (string) $input->getArgument('email');
        $user = $this->users->findByEmail($email);

        if (null === $user) {
            $io->error(sprintf('No hay ninguna cuenta con el correo "%s".', $email));

            return Command::FAILURE;
        }

        $revoke = (bool) $input->getOption('revoke');
        $roles = $user->getRoles();
        $hasRole = in_array(Roles::OPERATOR, $roles, true);

        if ($revoke && !$hasRole) {
            $io->note(sprintf('«%s» no era operador: no hay nada que retirar.', $user->getEmail()));

            return Command::SUCCESS;
        }

        if (!$revoke && $hasRole) {
            $io->note(sprintf('«%s» ya era operador.', $user->getEmail()));

            return Command::SUCCESS;
        }

        $user->setRoles($this->withoutOperator($roles, $revoke));

        $this->users->save($user);

        $io->success(sprintf(
            $revoke
                ? '«%s» deja de ser operador: ya no verá el catálogo.'
                : '«%s» es operador: verá el catálogo en el menú.',
            $user->getEmail(),
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    private function withoutOperator(array $roles, bool $revoke): array
    {
        if ($revoke) {
            return array_values(array_filter($roles, static fn (string $role): bool => Roles::OPERATOR !== $role));
        }

        $roles[] = Roles::OPERATOR;

        return array_values(array_unique($roles));
    }
}
