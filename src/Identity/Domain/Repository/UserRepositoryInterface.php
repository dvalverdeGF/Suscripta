<?php

declare(strict_types=1);

namespace App\Identity\Domain\Repository;

use App\Identity\Domain\Entity\User;
use Symfony\Component\Uid\Uuid;

interface UserRepositoryInterface
{
    public function find(Uuid $id): ?User;

    public function findByEmail(string $email): ?User;

    public function emailExists(string $email): bool;

    public function save(User $user, bool $flush = true): void;
}
