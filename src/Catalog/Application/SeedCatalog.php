<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\Entity\Category;
use App\Catalog\Domain\Entity\Provider;
use App\Catalog\Domain\Entity\ProviderIdentity;
use App\Catalog\Domain\Enum\ProviderIdentitySource;
use App\Catalog\Domain\Enum\ProviderIdentityType;
use App\Catalog\Domain\Repository\CategoryRepositoryInterface;
use App\Catalog\Domain\Repository\ProviderIdentityRepositoryInterface;
use App\Catalog\Domain\Repository\ProviderRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Siembra el catálogo global de categorías y proveedores.
 *
 * Es idempotente: se puede ejecutar en cada despliegue. Solo crea lo que falta
 * y nunca pisa lo que el usuario haya podido cambiar, porque el catálogo global
 * es de solo lectura para el tenant.
 */
final readonly class SeedCatalog
{
    public function __construct(
        private CategoryRepositoryInterface $categories,
        private ProviderRepositoryInterface $providers,
        private ProviderIdentityRepositoryInterface $identities,
        private EntityManagerInterface $entityManager,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    /**
     * @return array{categories: int, providers: int, identities: int}
     */
    public function __invoke(): array
    {
        $createdCategories = 0;
        $createdProviders = 0;
        $createdIdentities = 0;

        /** @var array<string, Category> $categoriesBySlug */
        $categoriesBySlug = [];

        foreach (CatalogSeedData::categories() as $sortOrder => $data) {
            $category = $this->categories->findGlobalBySlug($data['slug']);

            if (null === $category) {
                $category = new Category(
                    name: $data['name'],
                    slug: $data['slug'],
                    organizationId: null,
                    system: true,
                    color: $data['color'],
                    icon: $data['icon'],
                    sortOrder: $sortOrder,
                );

                $this->categories->save($category, false);
                ++$createdCategories;
            }

            $categoriesBySlug[$data['slug']] = $category;
        }

        foreach (CatalogSeedData::providers() as $data) {
            $provider = $this->providers->findGlobalBySlug($data['slug']);

            if (null === $provider) {
                $provider = new Provider(
                    name: $data['name'],
                    slug: $data['slug'],
                    organizationId: null,
                    system: true,
                );

                $provider->setWebsite($data['website']);
                $provider->setDefaultCategoryId($categoriesBySlug[$data['category']]->getId());

                $this->providers->save($provider, false);
                ++$createdProviders;
            }

            foreach ($data['domains'] as $domain) {
                if (null !== $this->identities->findByTypeAndValue(ProviderIdentityType::DOMAIN, $domain)) {
                    continue;
                }

                $this->identities->save(
                    new ProviderIdentity(
                        provider: $provider,
                        type: ProviderIdentityType::DOMAIN,
                        value: $domain,
                        source: ProviderIdentitySource::SEED,
                        confidence: 100,
                    ),
                    false,
                );

                ++$createdIdentities;
            }
        }

        $this->entityManager->flush();

        if ($createdCategories > 0 || $createdProviders > 0 || $createdIdentities > 0) {
            $this->auditLogger->log(
                action: AuditAction::CATALOG_SEEDED,
                targetType: 'catalog',
                metadata: [
                    'categories' => $createdCategories,
                    'providers' => $createdProviders,
                    'identities' => $createdIdentities,
                ],
            );
        }

        return [
            'categories' => $createdCategories,
            'providers' => $createdProviders,
            'identities' => $createdIdentities,
        ];
    }
}
