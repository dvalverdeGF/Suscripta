<?php

declare(strict_types=1);

namespace App\Mailbox\Application\Entitlements;

use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Shared\Application\Entitlements\Capability;
use App\Shared\Application\Entitlements\EntitlementUsageProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Cuántos buzones tiene conectados la organización activa (Fase 17).
 *
 * Las cuentas desconectadas no cuentan: un buzón desconectado sigue en la
 * base de datos con su historial, pero no es un buzón que la organización
 * use, y no debe ocupar hueco de un plan.
 *
 * El atributo `#[AsTaggedItem]` dice bajo qué clave se guarda este proveedor
 * en el localizador de cuotas (D-70).
 */
#[AsTaggedItem(Capability::EMAIL_ACCOUNTS)]
final readonly class EmailAccountUsageProvider implements EntitlementUsageProviderInterface
{
    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
    ) {
    }

    public function capability(): string
    {
        return Capability::EMAIL_ACCOUNTS;
    }

    public function usage(): int
    {
        return $this->accounts->countForOrganization();
    }
}
