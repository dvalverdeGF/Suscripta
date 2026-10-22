<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use App\Onboarding\Application\Dto\OnboardingStep;
use App\Services\Domain\Repository\ServiceRepositoryInterface;
use App\Shared\Application\Entitlements\Capability;
use App\Shared\Application\Entitlements\QuotaGuard;
use App\Shared\Application\OrganizationSettingsReader;

/**
 * Construye la lista de pasos de la guía de bienvenida (Fase 17).
 *
 * ## Qué es esta lista
 *
 * El camino más corto para que Suscripta haga su trabajo: tener un buzón de
 * donde llegan las facturas, tener al menos un servicio que controlar, y
 * saber que el producto va a avisar de las renovaciones.
 *
 * ## Por qué se calcula en vivo
 *
 * Porque el estado del producto es la verdad. Si el usuario conecta el buzón
 * desde la pantalla de buzones (y no desde la guía), la guía tiene que
 * enterarse. Un "paso 2 de 3" guardado en la base de datos mentiría.
 *
 * ## Por qué hay un paso que no se puede marcar
 *
 * El plan sin inteligencia artificial no puede activar la IA, así que en ese
 * caso el paso de la IA **no aparece**: no hay nada que hacer y ofrecerlo sería
 * una promesa que el producto no puede cumplir.
 */
final readonly class BuildOnboardingChecklist
{
    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
        private ServiceRepositoryInterface $services,
        private QuotaGuard $quota,
        private OrganizationSettingsReader $organizations,
    ) {
    }

    /**
     * Los pasos de la guía, en orden.
     *
     * @return list<OnboardingStep>
     */
    public function __invoke(): array
    {
        $organization = $this->organizations->current();
        $hasMailbox = [] !== $this->accounts->findForOrganization();
        $hasService = $this->services->countAll() > 0;
        $hasAi = $this->quota->allows(Capability::AI);

        $steps = [
            new OnboardingStep(
                key: 'organization',
                title: 'Crea tu organización',
                description: 'Ya está creada: es el espacio donde viven tus servicios y tus cuentas.',
                done: null !== $organization,
            ),
            new OnboardingStep(
                key: 'mailbox',
                title: 'Conecta el buzón donde llegan las facturas',
                description: 'Suscripta lee ese buzón, encuentra las facturas y las convierte en servicios. También puedes subir documentos a mano.',
                done: $hasMailbox,
                path: 'app_mail_accounts_new',
                action: 'Conectar un buzón',
            ),
            new OnboardingStep(
                key: 'service',
                title: 'Añade tu primer servicio',
                description: 'Es el punto de partida: sin un servicio no hay nada que controlar ni que facturar.',
                done: $hasService,
                path: 'app_services_new',
                action: 'Añadir un servicio',
            ),
        ];

        if ($hasAi) {
            $steps[] = new OnboardingStep(
                key: 'ai',
                title: 'Activa la inteligencia artificial',
                description: 'Con ella, Suscripta entiende las facturas en PDF y los correos en cualquier idioma, y te avisa antes de que se te escape una renovación.',
                done: false,
                path: 'app_ai_settings',
                action: 'Configurar la IA',
            );
        }

        return $steps;
    }
}
