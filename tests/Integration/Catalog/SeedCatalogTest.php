<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\CatalogSeedData;
use App\Catalog\Application\SeedCatalog;
use App\Catalog\Domain\Enum\ProviderIdentityType;
use App\Catalog\Domain\Repository\CategoryRepositoryInterface;
use App\Catalog\Domain\Repository\ProviderIdentityRepositoryInterface;
use App\Catalog\Domain\Repository\ProviderRepositoryInterface;

use function array_column;
use function array_unique;
use function sprintf;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * El sembrado del catálogo se ejecuta en cada despliegue, así que tiene que ser
 * idempotente: la segunda pasada no debe crear nada ni duplicar identidades.
 */
final class SeedCatalogTest extends KernelTestCase
{
    /**
     * Siembra el catálogo global antes de cada prueba.
     *
     * La siembra la hace el entrypoint de Docker al arrancar, pero la base de
     * datos de test no pasa por ahí. Sin esta llamada la prueba dependería del
     * estado previo de la base, que es justo lo que no debe comprobar.
     */
    protected function setUp(): void
    {
        self::bootKernel();

        (self::getContainer()->get(SeedCatalog::class))();
    }

    public function testSeedingTwiceCreatesNothingTheSecondTime(): void
    {
        $seed = self::getContainer()->get(SeedCatalog::class);

        $first = $seed();

        self::assertSame(
            ['categories' => 0, 'providers' => 0, 'identities' => 0],
            $first,
            'La siembra anterior en setUp() ya dejó el catálogo completo.',
        );

        $second = $seed();

        self::assertSame(['categories' => 0, 'providers' => 0, 'identities' => 0], $second);
    }

    public function testEverySeededCategoryAndProviderExists(): void
    {
        $container = self::getContainer();

        $categories = $container->get(CategoryRepositoryInterface::class);
        $providers = $container->get(ProviderRepositoryInterface::class);
        $identities = $container->get(ProviderIdentityRepositoryInterface::class);

        foreach (CatalogSeedData::categories() as $data) {
            self::assertNotNull($categories->findGlobalBySlug($data['slug']), sprintf('Falta la categoría «%s».', $data['slug']));
        }

        foreach (CatalogSeedData::providers() as $data) {
            $provider = $providers->findGlobalBySlug($data['slug']);

            self::assertNotNull($provider, sprintf('Falta el proveedor «%s».', $data['slug']));

            foreach ($data['domains'] as $domain) {
                $identity = $identities->findByTypeAndValue(ProviderIdentityType::DOMAIN, $domain);

                self::assertNotNull($identity, sprintf('Falta el dominio «%s» de «%s».', $domain, $data['slug']));
                self::assertTrue($identity->getProvider()->getId()->equals($provider->getId()));
            }
        }
    }

    public function testSeedDataHasNoDuplicateSlugsOrDomains(): void
    {
        $slugs = array_column(CatalogSeedData::providers(), 'slug');
        self::assertSame($slugs, array_unique($slugs), 'Hay slugs de proveedor repetidos.');

        $categorySlugs = array_column(CatalogSeedData::categories(), 'slug');
        self::assertSame($categorySlugs, array_unique($categorySlugs), 'Hay slugs de categoría repetidos.');

        $domains = [];
        foreach (CatalogSeedData::providers() as $data) {
            foreach ($data['domains'] as $domain) {
                $domains[] = $domain;
            }
        }

        self::assertSame($domains, array_unique($domains), 'Hay dominios repetidos entre proveedores.');
    }

    public function testEveryProviderPointsToADeclaredCategory(): void
    {
        $declared = array_column(CatalogSeedData::categories(), 'slug');

        foreach (CatalogSeedData::providers() as $data) {
            self::assertContains(
                $data['category'],
                $declared,
                sprintf('El proveedor «%s» apunta a la categoría inexistente «%s».', $data['slug'], $data['category']),
            );
        }
    }
}
