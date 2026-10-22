<?php

declare(strict_types=1);

namespace App\Api\UI\Security;

use App\Api\UI\Http\Problem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Punto de entrada del firewall de la API.
 *
 * ## Por qué hace falta
 *
 * Cuando una petición llega sin credencial (o con una credencial inválida),
 * el firewall llama a este punto de entrada. Lo que devuelve es la **única**
 * forma de `401` de la API: un `problem+json` con el mismo esquema que
 * cualquier otro error. Un `401` con la página de login del backoffice —lo
 * que haría el firewall principal— dejaría al integrador con un HTML donde
 * esperaba JSON.
 *
 * ## La cabecera `WWW-Authenticate`
 *
 * La RFC 9110 dice que un `401` debe decir **cómo** autenticarse. Se indica
 * el esquema `Bearer`, que es el que usa la API.
 */
final readonly class ApiAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $response = Problem::unauthorized()->toResponse($request->getPathInfo());

        $response->headers->set('WWW-Authenticate', 'Bearer realm="Suscripta API"');

        return $response;
    }
}
