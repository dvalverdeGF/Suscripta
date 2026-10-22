<?php

declare(strict_types=1);

namespace App\Privacy\UI\Controller;

use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Enum\OrganizationRole;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Privacy\Application\DeleteOrganizationData;
use App\Privacy\Application\ExportOrganizationData;
use App\Shared\Application\Privacy\PrivacySettings;
use App\Shared\Application\RateLimit\RateLimitGuard;
use App\Shared\Application\TenantContext;

use function in_array;
use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Panel de privacidad (Fase 16).
 *
 * ## Qué es
 *
 * La pantalla donde el usuario ve **qué datos guarda Suscripta de él**, se los
 * lleva (derecho de acceso y portabilidad) y pide que se borren (derecho de
 * supresión). Es la parte visible de SECURITY.md §6.
 *
 * ## Tres decisiones
 *
 * 1. **El borrado es de la organización, no de la persona.** El usuario puede
 *    tener varias organizaciones, y su cuenta es suya: lo que se borra es lo
 *    que esa organización guardó de él.
 * 2. **El borrado no se ejecuta con un clic.** Pide escribir el nombre de la
 *    organización, porque es irreversible y no tiene vuelta atrás: los
 *    servicios confirmados, los precios y el histórico se van con ella.
 * 3. **Quién puede borrarla:** un propietario o un administrador. Un miembro
 *   _invitado_ no puede llevarse los datos de la organización.
 *
 * ## Lo que no se exporta
 *
 * Ni contraseñas, ni credenciales cifradas, ni claves de IA. Un archivo de
 * exportación se manda por correo: llevar secretos dentro sería peor que no
 * exportarlos (SECURITY.md §9).
 */
#[Route('/privacy', name: 'app_privacy_')]
#[IsGranted('ROLE_USER')]
final class PrivacyController extends AbstractController
{
    public function __construct(
        private readonly ExportOrganizationData $export,
        private readonly DeleteOrganizationData $delete,
        private readonly PrivacySettings $settings,
        private readonly TenantContext $tenantContext,
        private readonly OrganizationRepositoryInterface $organizations,
        private readonly RateLimitGuard $rateLimits,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $organizationId = $this->tenantContext->requireOrganizationId();
        $organization = $this->organizations->find($organizationId);

        return $this->render('backoffice/privacy/index.html.twig', [
            'organization' => $organization,
            'settings' => $this->settings,
            'canDelete' => $this->canDelete(),
        ]);
    }

    #[Route('/export.json', name: 'export_json', methods: ['GET'])]
    public function exportJson(): Response
    {
        $organizationId = $this->tenantContext->requireOrganizationId();

        $this->assertExportAllowed($organizationId);

        $export = ($this->export)($organizationId, $this->actorId());

        $response = new Response($export->toJson());
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                sprintf('suscripta-datos-%s.json', $organizationId->toRfc4122()),
            ),
        );

        return $response;
    }

    #[Route('/export/{section}.csv', name: 'export_csv', methods: ['GET'], requirements: ['section' => '[a-z_]+'])]
    public function exportCsv(string $section): Response
    {
        $organizationId = $this->tenantContext->requireOrganizationId();

        $this->assertExportAllowed($organizationId);

        $export = ($this->export)($organizationId, $this->actorId());

        if (!in_array($section, $export->sectionNames(), true)) {
            throw $this->createNotFoundException('La sección solicitada no existe.');
        }

        $csv = $export->toCsv($section);

        if ('' === $csv) {
            throw $this->createNotFoundException('La sección solicitada no tiene datos.');
        }

        $response = new Response($csv);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                sprintf('suscripta-%s-%s.csv', $section, $organizationId->toRfc4122()),
            ),
        );

        return $response;
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('delete_organization_data', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        if (!$this->canDelete()) {
            throw $this->createAccessDeniedException('Solo un propietario o un administrador puede borrar los datos de la organización.');
        }

        $organizationId = $this->tenantContext->requireOrganizationId();
        $organization = $this->organizations->find($organizationId);

        if (null === $organization) {
            throw $this->createNotFoundException('La organización no existe.');
        }

        // Confirmación explícita: el nombre de la organización. Un borrado
        // irreversible no puede depender de un clic en un botón.
        $confirmation = trim((string) $request->request->get('confirmation', ''));

        if ($confirmation !== $organization->getName()) {
            $this->addFlash('error', 'El nombre de la organización no coincide. No se ha borrado nada.');

            return $this->redirectToRoute('app_privacy_index');
        }

        $report = ($this->delete)($organizationId, $this->actorId());

        $this->addFlash(
            'success',
            sprintf('Se han borrado %d elementos de la organización.', $report->total()),
        );

        // La borración se lleva la organización entera, así que el usuario
        // puede quedarse sin ninguna. Si es el caso, la pantalla de
        // privacidad ya no se puede pintar: se ofrece empezar de cero.
        return $this->redirectToRoute($this->hasOrganization() ? 'app_privacy_index' : 'app_organization_new');
    }

    /**
     * La exportación se limita por organización.
     *
     * Exportar recorre todos los datos de la organización y arma un fichero
     * en memoria. Es una operación de lectura pesada y no hace falta repetirla
     * diez veces seguidas para saber qué contiene.
     */
    private function assertExportAllowed(Uuid $organizationId): void
    {
        if ($this->rateLimits->acquire('privacy_export', $organizationId->toRfc4122())) {
            return;
        }

        throw new TooManyRequestsHttpException(60, 'Has pedido demasiadas exportaciones seguidas. Espera un minuto y vuelve a intentarlo.');
    }

    private function actorId(): ?Uuid
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getId() : null;
    }

    /**
     * Solo un propietario o un administrador puede dejar la organización sin
     * datos. Un miembro invitado no.
     */
    private function canDelete(): bool
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return false;
        }

        $organizationId = $this->tenantContext->getOrganizationId();

        if (null === $organizationId) {
            return false;
        }

        foreach ($user->getMemberships() as $membership) {
            if ($membership->getOrganization()->getId()->equals($organizationId)) {
                return in_array(
                    $membership->getRole(),
                    [OrganizationRole::OWNER, OrganizationRole::ADMIN],
                    true,
                );
            }
        }

        return false;
    }

    /**
     * ¿Le queda alguna organización al usuario?
     *
     * La borración de datos se lleva la organización consigo, y con ella las
     * membresías: un usuario puede quedarse sin ninguna y entonces el
     * backoffice no tiene nada que mostrar.
     */
    private function hasOrganization(): bool
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return [] !== $this->organizations->findForUser($user);
    }
}
