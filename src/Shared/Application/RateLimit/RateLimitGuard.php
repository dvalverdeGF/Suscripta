<?php

declare(strict_types=1);

namespace App\Shared\Application\RateLimit;

use LogicException;

use function sprintf;

use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Puerta de frecuencia compartida (Fase 16, `f16-security`).
 *
 * ## Qué protege
 *
 * Las operaciones que salen de la aplicacion hacia fuera y cuestan tiempo o
 * dinero: abrir una conexion IMAP, sincronizar un buzon, subir un documento,
 * generar una exportacion. Un usuario (o un script que se ha colado en su
 * sesion) puede repetirlas sin parar.
 *
 * ## Por que el limite va por organizacion y no por IP
 *
 * Por IP no sirve: detras de un proxy o un balanceador todas las peticiones
 * comparten direccion, asi que el limite seria global y un cliente pesado
 * dejaria al resto sin servicio. La organizacion es la unidad real de
 * consumo, y ademas es la que se puede explicar ("su organizacion ha
 * reached el limite de sincronizaciones").
 *
 * ## Por que no se usa el atributo `#[RateLimit]`
 *
 * El atributo del kernel solo conoce la peticion, y de ahi no se puede
 * sacar la organizacion activa. Aqui la clave la decide quien llama, que si
 * la conoce.
 */
final readonly class RateLimitGuard
{
    /**
     * @param ServiceProviderInterface<RateLimiterFactoryInterface> $limiters
     */
    public function __construct(private ServiceProviderInterface $limiters)
    {
    }

    /**
     * Consume un hueco del limite.
     *
     * Devuelve `false` cuando ya no queda ninguno: el llamante debe
     * responder "demasiado rapido" en lugar de ejecutar la operacion.
     */
    public function acquire(string $limiter, string $key, int $tokens = 1): bool
    {
        return $this->consume($limiter, $key, $tokens)->isAccepted();
    }

    /**
     * Consume un hueco del limite y devuelve el detalle del contador.
     *
     * Lo que devuelve `acquire()` es un si o un no, y para responder a un
     * cliente que ha superado el limite hace falta mas: cuanto queda, cuando
     * se repone, cual era el tope. Por eso existe este metodo, que es el que
     * usan los que deben informar (la API, con sus cabeceras `X-RateLimit`).
     */
    public function consume(string $limiter, string $key, int $tokens = 1): RateLimit
    {
        if (!$this->limiters->has($limiter)) {
            throw new LogicException(sprintf('El limitador "%s" no esta configurado.', $limiter));
        }

        return $this->limiters->get($limiter)->create($key)->consume($tokens);
    }
}
