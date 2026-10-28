<?php

declare(strict_types=1);

namespace App\Website\UI\Controller;

use App\Website\Application\Faq;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Las páginas de la web corporativa que no necesitan datos (Fase 23).
 *
 * Son acciones de una línea a propósito: el contenido vive en la plantilla y no
 * en la base de datos, porque es texto que cambia con un despliegue y no con un
 * formulario. La única excepción es la página de precios, que sí lee el catálogo
 * (`PricingController`), y las preguntas frecuentes, que se definen en `Faq`
 * para que el marcado de datos estructurados use exactamente el mismo texto que
 * se ve.
 *
 * Ninguna de estas rutas lleva `#[IsGranted]`: son públicas por diseño. Lo que
 * las hace accesibles sin sesión es la lista blanca de `access_control`, y hay
 * un test que recorre todas las rutas `app_site_*` sin autenticarse para que
 * añadir una página y olvidar el permiso no pase desapercibido (D-84).
 */
final class SiteController extends AbstractController
{
    #[Route('/producto', name: 'app_site_product', methods: ['GET'])]
    public function product(): Response
    {
        return $this->render('website/product.html.twig');
    }

    #[Route('/como-funciona', name: 'app_site_how_it_works', methods: ['GET'])]
    public function howItWorks(): Response
    {
        return $this->render('website/how_it_works.html.twig');
    }

    #[Route('/integraciones', name: 'app_site_integrations', methods: ['GET'])]
    public function integrations(): Response
    {
        return $this->render('website/integrations.html.twig');
    }

    #[Route('/seguridad', name: 'app_site_security', methods: ['GET'])]
    public function security(): Response
    {
        return $this->render('website/security.html.twig');
    }

    #[Route('/preguntas-frecuentes', name: 'app_site_faq', methods: ['GET'])]
    public function faq(Faq $faq): Response
    {
        return $this->render('website/faq.html.twig', [
            'questions' => $faq->items(),
        ]);
    }

    #[Route('/aviso-legal', name: 'app_site_legal_notice', methods: ['GET'])]
    public function legalNotice(): Response
    {
        return $this->render('website/legal/notice.html.twig');
    }

    #[Route('/privacidad', name: 'app_site_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('website/legal/privacy.html.twig');
    }

    #[Route('/condiciones', name: 'app_site_terms', methods: ['GET'])]
    public function terms(): Response
    {
        return $this->render('website/legal/terms.html.twig');
    }
}
