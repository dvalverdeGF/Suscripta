<?php

declare(strict_types=1);

namespace App\Processing\Domain\Billing;

use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Processing\Domain\Dto\BillingScoreResult;

/**
 * Filtro determinista del nivel 2 (ARCHITECTURE.md §13.4, D-31).
 *
 * Es el guardián de coste del pipeline: lo que no pasa por aquí **no se
 * descarga**. Por eso el pipeline depende de esta interfaz y no de una
 * implementación concreta: la decisión de qué merece analizarse es una política
 * de producto, y debe poder sustituirse (por ejemplo, por un clasificador
 * entrenado con las confirmaciones del propio usuario) sin tocar el
 * orquestador.
 *
 * El contrato exige que el resultado sea **explicable**: cada punto viene de una
 * señal con su peso y su detalle, y se persiste en `EmailMessage.billingReasons`
 * para poder responder "¿por qué descartaste este correo?".
 */
interface BillingClassifierInterface
{
    /**
     * @param string|null $bodyText extracto del cuerpo, si ya se ha descargado.
     *                              En el primer pase es `null` y la puntuación
     *                              se calcula solo con metadatos, que es lo que
     *                              permite descartar sin descargar nada.
     */
    public function score(ImapMessageHeader $header, ?string $bodyText = null): BillingScoreResult;
}
