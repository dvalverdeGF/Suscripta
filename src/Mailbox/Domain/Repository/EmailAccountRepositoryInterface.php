<?php

declare(strict_types=1);

namespace App\Mailbox\Domain\Repository;

use App\Mailbox\Domain\Entity\EmailAccount;
use Symfony\Component\Uid\Uuid;

interface EmailAccountRepositoryInterface
{
    public function find(Uuid $id): ?EmailAccount;

    /**
     * Cuentas vivas de la organización activa.
     *
     * @return list<EmailAccount>
     */
    public function findForOrganization(): array;

    /**
     * Cuentas que pueden sincronizarse ahora mismo.
     *
     * @return list<EmailAccount>
     */
    public function findSyncable(): array;

    public function findByAddress(string $emailAddress): ?EmailAccount;

    /**
     * Cuenta cuya dirección de ingesta por reenvío es esta (D-21).
     *
     * Se busca **sin** contexto de organización: la petición llega de fuera, sin
     * sesión, y es la propia dirección la que determina a qué organización
     * pertenece el correo.
     */
    public function findByForwardingAddress(string $address): ?EmailAccount;

    public function countForOrganization(): int;

    public function save(EmailAccount $account, bool $flush = true): void;

    public function remove(EmailAccount $account, bool $flush = true): void;
}
