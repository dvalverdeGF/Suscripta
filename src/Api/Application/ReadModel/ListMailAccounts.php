<?php

declare(strict_types=1);

namespace App\Api\Application\ReadModel;

use App\Api\Application\ApiCollection;
use App\Mailbox\Domain\Repository\EmailAccountViewRepositoryInterface;

/**
 * Listado de buzones de la organización.
 */
final readonly class ListMailAccounts
{
    public function __construct(
        private EmailAccountViewRepositoryInterface $accounts,
    ) {
    }

    public function __invoke(int $page = 1, int $perPage = 50): ApiCollection
    {
        $result = $this->accounts->findPage($page, $perPage);

        $items = [];
        foreach ($result->items as $account) {
            $items[] = ResourceMapper::mailAccount($account);
        }

        return ApiCollection::of($items, $result->pagination);
    }
}
