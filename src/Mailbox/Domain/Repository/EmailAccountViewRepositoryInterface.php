<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Repository;

use App\Mailbox\Domain\Dto\EmailAccountView;
use App\Shared\Application\Page;

use Symfony\Component\Uid\Uuid;

interface EmailAccountViewRepositoryInterface
{
    /**
     * Cuentas de la organización activa, paginadas.
     */
    public function findPage(int $page = 1, int $perPage = 50): Page;

    /**
     * Una cuenta concreta de la organización activa.
     */
    public function findOne(Uuid $id): ?EmailAccountView;
}
