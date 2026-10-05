<?php

declare(strict_types=1);

namespace App\Processing\UI\Command;

use App\Mailbox\Application\SyncEmailAccount;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Processing\Application\Message\ProcessEmailMessageMessage;

use function is_string;
use function sprintf;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Sincroniza buzones y encola su procesamiento.
 *
 * Es el punto de entrada manual del pipeline: sirve para la primera
 * sincronización, para depurar y para el cron de producción. Sin argumentos
 * recorre todos los buzones activos de todas las organizaciones, que es lo que
 * hará el planificador.
 */
#[AsCommand(
    name: 'app:mail:sync',
    description: 'Sincroniza los buzones IMAP y encola el análisis de los mensajes nuevos.',
)]
final class SyncEmailAccountCommand extends Command
{
    public function __construct(
        private readonly EmailAccountRepositoryInterface $accounts,
        private readonly EmailMessageRepositoryInterface $messages,
        private readonly SyncEmailAccount $syncEmailAccount,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('account', InputArgument::OPTIONAL, 'Identificador del buzón. Si se omite, se sincronizan todos.')
            ->addOption('months', null, InputOption::VALUE_REQUIRED, 'Ventana de antigüedad en meses.', (string) SyncEmailAccount::DEFAULT_MONTHS)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de mensajes por buzón.', (string) SyncEmailAccount::DEFAULT_LIMIT)
            ->addOption('no-process', null, InputOption::VALUE_NONE, 'Solo sincroniza; no encola el análisis.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $months = (int) $input->getOption('months');
        $limit = (int) $input->getOption('limit');
        $process = !$input->getOption('no-process');

        $accounts = $this->resolveAccounts($input->getArgument('account'));

        if ([] === $accounts) {
            $io->warning('No hay ningún buzón que sincronizar.');

            return Command::SUCCESS;
        }

        $failures = 0;

        foreach ($accounts as $account) {
            $io->section(sprintf('%s (%s)', $account->getEmailAddress(), $account->getImapHost() ?? 'sin servidor'));

            try {
                $run = ($this->syncEmailAccount)($account, null, $months, $limit);
            } catch (Throwable $e) {
                ++$failures;
                $io->error(sprintf('No se ha podido sincronizar: %s', $e->getMessage()));

                continue;
            }

            $io->text(sprintf(
                'Vistos %d, nuevos %d, ya conocidos %d.',
                $run->getMessagesSeen(),
                $run->getMessagesProcessed(),
                $run->getMessagesSkipped(),
            ));

            if (!$process || 0 === $run->getMessagesProcessed()) {
                continue;
            }

            $queued = $this->enqueuePending($account->getId());
            $io->text(sprintf('Encolados %d mensajes para análisis.', $queued));
        }

        if ($failures > 0) {
            $io->warning(sprintf('%d buzones han fallado.', $failures));

            return Command::FAILURE;
        }

        $io->success('Sincronización terminada.');

        return Command::SUCCESS;
    }

    /**
     * Encola los mensajes que todavía no se han procesado.
     *
     * Se apoya en el estado del mensaje y no en lo que acaba de devolver la
     * sincronización: así el comando también sirve para recuperar un backlog
     * que quedó a medias, que es justo lo que hace falta cuando algo falla.
     */
    private function enqueuePending(Uuid $accountId): int
    {
        $queued = 0;

        foreach ($this->messages->findPendingForAccount($accountId) as $message) {
            $this->bus->dispatch(
                new ProcessEmailMessageMessage($message->getId()),
                [new TransportNamesStamp(['mail_processing'])],
            );

            ++$queued;
        }

        return $queued;
    }

    /**
     * @return list<\App\Mailbox\Domain\Entity\EmailAccount>
     */
    private function resolveAccounts(mixed $identifier): array
    {
        if (is_string($identifier) && '' !== $identifier) {
            $account = $this->accounts->find(Uuid::fromString($identifier));

            return null === $account ? [] : [$account];
        }

        return $this->accounts->findSyncable();
    }
}
