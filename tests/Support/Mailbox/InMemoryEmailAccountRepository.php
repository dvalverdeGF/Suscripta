<?php

declare(strict_types=1);

namespace App\Tests\Support\Mailbox;

use App\Mailbox\Domain\Entity\EmailAccount;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;

use function array_values;
use function count;

use Symfony\Component\Uid\Uuid;

/**
 * Repositorio de buzones en memoria con almacenamiento real.
 */
final class InMemoryEmailAccountRepository implements EmailAccountRepositoryInterface
{
    /** @var array<string, EmailAccount> */
    private array $accounts = [];

    public int $flushCount = 0;

    /** @return list<EmailAccount> */
    public function all(): array
    {
        return array_values($this->accounts);
    }

    public function find(Uuid $id): ?EmailAccount
    {
        return $this->accounts[$id->toRfc4122()] ?? null;
    }

    public function findForOrganization(): array
    {
        return $this->all();
    }

    public function findSyncable(): array
    {
        return $this->all();
    }

    public function findByAddress(string $emailAddress): ?EmailAccount
    {
        foreach ($this->accounts as $account) {
            if ($account->getEmailAddress() === $emailAddress) {
                return $account;
            }
        }

        return null;
    }

    public function findByForwardingAddress(string $address): ?EmailAccount
    {
        foreach ($this->accounts as $account) {
            if ($account->isForwardingEnabled() && $account->getForwardingAddress() === $address) {
                return $account;
            }
        }

        return null;
    }

    public function countForOrganization(): int
    {
        return count($this->accounts);
    }

    public function save(EmailAccount $account, bool $flush = true): void
    {
        $this->accounts[$account->getId()->toRfc4122()] = $account;

        if ($flush) {
            $this->flush();
        }
    }

    public function remove(EmailAccount $account, bool $flush = true): void
    {
        unset($this->accounts[$account->getId()->toRfc4122()]);

        if ($flush) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        ++$this->flushCount;
    }
}
