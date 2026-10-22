<?php

declare(strict_types=1);

namespace App\Shared\UI\EventListener;

use function is_string;
use function sprintf;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Cabeceras de seguridad HTTP (Fase 16, `f16-security`).
 *
 * ## Por qué un listener y no el servidor web
 *
 * Caddy (el servidor de la imagen) se puede cambiar; el kernel no. Y estas
 * cabeceras son parte de la **aplicación**: si mañana el proyecto se despliega
 * detrás de nginx, Apache o un balanceador, las cabeceras siguen saliendo.
 *
 * ## El nonce
 *
 * La CSP no puede llevar `'unsafe-inline'` en `script-src`: con eso, un
 * `<script>` inyectado en el HTML se ejecuta. Pero esta aplicación genera
 * JavaScript **en línea** (el `<script type="importmap">` y el
 * `<script type="module">` que el importmap de Symfony emite), así que hace
 * falta un **nonce** por respuesta: un valor aleatorio que el navegador
 * comprueba contra la cabecera y que un atacante no puede conocer de antemano
 * porque no puede ejecutar JavaScript en la página.
 *
 * El nonce se genera al empezar la petición (para que la plantilla pueda
 * leerlo) y se aplica al responder.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 1024)]
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -64)]
final class SecurityHeadersListener
{
    /**
     * Atributo de la petición donde se guarda el nonce.
     *
     * La plantilla lo lee con `csp_nonce()` (ver `CspExtension`).
     */
    public const NONCE_ATTRIBUTE = '_csp_nonce';

    /**
     * La cabecera `Content-Security-Policy` tal cual.
     *
     * - `default-src 'self'`: por defecto, nada de fuera.
     * - `script-src 'self' 'nonce-…'`: nada de scripts en línea sin nonce.
     * - `style-src`: hace falta `'unsafe-inline'` porque el SCSS compilado
     *   trae estilos en línea y Bootstrap los usa; las hojas de estilo
     *   siguen viniendo de Google Fonts, que hay que permitir explícitamente.
     * - `font-src`: las tipografías de Google Fonts se sirven desde
     *   `fonts.gstatic.com`, que es un origen distinto al que sirve las CSS.
     * - `frame-ancestors 'none'`: nadie puede meter esta aplicación en un
     *   `<iframe>` (clickjacking).
     * - `object-src 'none'`: ni `<object>` ni `<embed>`.
     * - `form-action 'self'`: los formularios solo pueden enviarse aquí.
     * - `base-uri 'self'`: el `<base>` no puede redirigir las rutas.
     */
    private const CSP = "default-src 'self'; "
        ."script-src 'self' 'nonce-%s'; "
        ."style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        ."font-src 'self' https://fonts.gstatic.com data:; "
        ."img-src 'self' data:; "
        ."connect-src 'self'; "
        ."frame-ancestors 'none'; "
        ."object-src 'none'; "
        ."form-action 'self'; "
        ."base-uri 'self'";

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->attributes->set(self::NONCE_ATTRIBUTE, bin2hex(random_bytes(16)));
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $request = $event->getRequest();
        $nonce = $request->attributes->get(self::NONCE_ATTRIBUTE);

        if (!is_string($nonce) || '' === $nonce) {
            $nonce = bin2hex(random_bytes(16));
        }

        $response->headers->set('Content-Security-Policy', sprintf(self::CSP, $nonce));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // HSTS solo tiene sentido sobre HTTPS: announcing it en local
        // dejaría al navegador del desarrollo bloqueando el sitio.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }
}
