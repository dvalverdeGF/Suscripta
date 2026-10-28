<?php

declare(strict_types=1);

namespace App\Website\UI\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * `sitemap.xml` y `robots.txt`.
 *
 * Los dos se generan desde la tabla de rutas y no se escriben a mano: un
 * sitemap escrito a mano envejece a la primera página nueva, y un `robots.txt`
 * a mano se olvida de la ruta privada que se añadió ayer.
 *
 * El sitemap usa `SITE_URL` y no el host de la petición: detrás de un proxy, el
 * host que ve la aplicación puede ser el interno, y un sitemap con
 * `http://localhost/...` no lo indexa nadie.
 */
final class SeoController extends AbstractController
{
    /**
     * Las páginas públicas, con su prioridad relativa.
     *
     * La prioridad no es decorativa: le dice al rastreador qué es la portada y
     * qué es una página legal. `lastmod` no se pone porque estas páginas cambian
     * con un despliegue y no con una fecha que podamos afirmar sin mentir.
     */
    private const array PAGES = [
        'app_home' => '1.0',
        'app_site_product' => '0.9',
        'app_site_pricing' => '0.9',
        'app_site_how_it_works' => '0.8',
        'app_site_integrations' => '0.8',
        'app_site_security' => '0.7',
        'app_site_faq' => '0.7',
        'app_site_contact' => '0.6',
        'app_site_legal_notice' => '0.2',
        'app_site_privacy' => '0.3',
        'app_site_terms' => '0.2',
    ];

    public function __construct(
        #[Autowire('%app.site.url%')]
        private readonly string $siteUrl,
    ) {
    }

    #[Route('/sitemap.xml', name: 'app_site_sitemap', methods: ['GET'])]
    public function sitemap(): Response
    {
        $urls = [];

        foreach (self::PAGES as $route => $priority) {
            $urls[] = [
                'loc' => $this->siteUrl.$this->generateUrl($route, [], UrlGeneratorInterface::ABSOLUTE_PATH),
                'priority' => $priority,
            ];
        }

        return $this->render('website/seo/sitemap.xml.twig', [
            'urls' => $urls,
        ], new Response(headers: ['Content-Type' => 'application/xml; charset=UTF-8']));
    }

    #[Route('/robots.txt', name: 'app_site_robots', methods: ['GET'])]
    public function robots(): Response
    {
        $body = implode("\n", [
            'User-agent: *',
            'Allow: /',
            '',
            '# El panel y la API no se indexan: no aportan nada al visitante y',
            '# exponen la estructura interna de la aplicación.',
            'Disallow: /dashboard',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /reset-password',
            'Disallow: /verify',
            'Disallow: /onboarding',
            'Disallow: /api/',
            'Disallow: /mail/inbound',
            '',
            'Sitemap: '.$this->siteUrl.'/sitemap.xml',
            '',
        ]);

        return new Response($body, headers: ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
