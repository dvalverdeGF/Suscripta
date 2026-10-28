<?php

declare(strict_types=1);

namespace App\Billing\Domain\Port;

use App\Billing\Domain\Exception\GatewayNotConfiguredException;
use App\Billing\Domain\Exception\GatewayRequestException;
use App\Billing\Domain\Exception\InvalidSignatureException;
use App\Billing\Domain\ValueObject\CheckoutRequest;
use App\Billing\Domain\ValueObject\CheckoutSession;
use App\Billing\Domain\ValueObject\GatewayCheckoutSession;
use App\Billing\Domain\ValueObject\GatewayEvent;
use App\Billing\Domain\ValueObject\GatewaySubscription;

/**
 * Lo que Suscripta necesita de un cobrador (Fase 24).
 *
 * ## Cinco operaciones y ninguna más
 *
 * Abrir un checkout, consultar una sesión, consultar una suscripción, leer un
 * evento firmado y comprobar si está configurado. Todo lo demás —clientes,
 * métodos de pago, impuestos, recibos— es de la pasarela y no se copia aquí:
 * cada operación que se añade a un puerto es una que hay que implementar y
 * probar en la siguiente pasarela.
 *
 * ## Por qué es un puerto y no una clase de Stripe
 *
 * No es por tener dos implementaciones algún día; es para poder probar el
 * cobro sin red. Los tests funcionales sustituyen este servicio por un doble y
 * comprueban el flujo completo —checkout, vuelta, webhook, panel— en
 * milisegundos. Con la clase de Stripe cableada al controlador, cada prueba de
 * esa cadena necesitaría un servidor falso de Stripe.
 */
interface PaymentGatewayInterface
{
    /**
     * Si hay credenciales guardadas y la pasarela se puede usar.
     *
     * La web lo consulta antes de enseñar el botón de contratar: ofrecer un
     * botón que lleva a un error de configuración es peor que no ofrecerlo.
     */
    public function isConfigured(): bool;

    /**
     * @throws GatewayNotConfiguredException
     * @throws GatewayRequestException
     */
    public function createCheckoutSession(CheckoutRequest $request): CheckoutSession;

    /**
     * @throws GatewayNotConfiguredException
     * @throws GatewayRequestException
     */
    public function findCheckoutSession(string $externalId): ?GatewayCheckoutSession;

    /**
     * @throws GatewayNotConfiguredException
     * @throws GatewayRequestException
     */
    public function findSubscription(string $externalId): ?GatewaySubscription;

    /**
     * Verifica la firma del cuerpo y lo traduce a un evento.
     *
     * @throws InvalidSignatureException     si la firma no es válida
     * @throws GatewayNotConfiguredException si no hay secreto con el que verificar
     */
    public function parseEvent(string $payload, string $signatureHeader): GatewayEvent;
}
