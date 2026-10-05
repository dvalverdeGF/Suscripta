<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\OrganizationRepositoryInterface;
use App\Shared\Application\Audit\AuditLoggerInterface;
use App\Shared\Domain\Enum\AuditAction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Cambia la organización activa de la sesión.
 *
 * La organización se guarda en la sesión, no en el usuario: es una preferencia
 * de navegación, no un dato de la cuenta. Al leerla, `ActiveOrganizationListener`
 * vuelve a validar que el usuario siga siendo miembro, así que un valor
 * manipulado en la sesión no da acceso a nada.
 */
final class OrganizationSwitchController extends AbstractController
{
    #[Route('/organizations/switch', name: 'app_organization_switch', methods: ['POST'])]
    public function __invoke(
        Request $request,
        OrganizationRepositoryInterface $organizations,
        AuditLoggerInterface $auditLogger,
    ): Response {
        if (!$this->isCsrfTokenValid('switch_organization', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $id = $request->request->getString('organization');

        if (!Uuid::isValid($id)) {
            $this->addFlash('error', 'La organización indicada no es válida.');

            return $this->redirectToRoute('app_dashboard');
        }

        $target = Uuid::fromString($id);
        $allowed = false;

        foreach ($organizations->findForUser($user) as $membership) {
            if ($membership['organization']->getId()->equals($target)) {
                $allowed = true;
                break;
            }
        }

        if (!$allowed) {
            $this->addFlash('error', 'No perteneces a esa organización.');

            return $this->redirectToRoute('app_dashboard');
        }

        $request->getSession()->set('active_organization_id', $target->toRfc4122());

        $auditLogger->log(
            action: AuditAction::ORGANIZATION_SWITCHED,
            targetType: 'organization',
            targetId: $target->toRfc4122(),
        );

        return $this->redirectToRoute('app_dashboard');
    }
}
