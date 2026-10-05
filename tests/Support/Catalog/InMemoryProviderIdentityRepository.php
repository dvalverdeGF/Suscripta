<?php

declare(strict_types=1);

namespace App\Tests\Support\Catalog;

use App\Catalog\Domain\Entity\ProviderIdentity;
use App\Catalog\Domain\Enum\ProviderIdentityType;
use App\Catalog\Domain\Repository\ProviderIdentityRepositoryInterface;
use Symfony\Component\Uid\Uuid;

use function array_values;

/**
 * Doble en memoria de `ProviderIdentityRepositoryInterface`.
 *
 * Guarda las **mismas instancias** que recibe, para que un test pueda comprobar
 * después que `recordHit()` se ha aplicado de verdad sobre la identidad que
 * estaba en el catálogo.
 */
final class InMemoryProviderIdentityRepository implements ProviderIdentityRepositoryInterface
{
    /** @var array<string, ProviderIdentity> */
    private array $identities = [];

    public int $flushCount = 0;

    /** Cuántas veces se ha pedido el catálogo completo de un tipo. */
    public int $findByTypeCalls = 0;

    /** @var array<string, int> */
    public array $findByTypeCallsByType = [];

    public function add(ProviderIdentity $identity): void
    {
        $this->identities[$identity->getId()->toRfc4122()] = $identity;
    }

    /**
     * @return list<ProviderIdentity>
     */
    public function all(): array
    {
        return array_values($this->identities);
    }

    public function findByTypeAndValue(ProviderIdentityType $type, string $value): ?ProviderIdentity
    {
        foreach ($this->identities as $identity) {
            if ($identity->getType() === $type && $identity->getValue() === $value) {
                return $identity;
            }
        }

        return null;
    }

    public function findForProvider(Uuid $providerId): array
    {
        $found = [];

        foreach ($this->identities as $identity) {
            if ($identity->getProvider()->getId()->equals($providerId)) {
                $found[] = $identity;
            }
        }

        return $found;
    }

    public function findByType(ProviderIdentityType $type): array
    {
        ++$this->findByTypeCalls;
        $this->findByTypeCallsByType[$type->value] = ($this->findByTypeCallsByType[$type->value] ?? 0) + 1;

        $found = [];

        foreach ($this->identities as $identity) {
            if ($identity->getType() === $type) {
                $found[] = $identity;
            }
        }

        return $found;
    }

    public function save(ProviderIdentity $identity, bool $flush = true): void
    {
        $this->identities[$identity->getId()->toRfc4122()] = $identity;

        if ($flush) {
            ++$this->flushCount;
        }
    }
}
