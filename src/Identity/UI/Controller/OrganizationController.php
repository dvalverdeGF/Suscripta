<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Application\CreateOrganization;
use App\Identity\Domain\Entity\User;
use App\Identity\UI\Form\OrganizationFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Creación de una organización.
 *
 * ## Por qué existe
 *
 * El backoffice da por hecho que el usuario tiene al menos una organización
 * (DECISIONS.md D-02). Ese supuesto se rompe en un caso: cuando el usuario
 * borra los datos de su organización, la borración se lleva la organización
 * entera (Fase 16, derecho de supresión) y el usuario se queda sin ninguna.
 *
 * Sin esta pantalla, ese usuario vería un error 500 en cada página. Con ella,
 * ve una explicación y un botón para empezar de cero.
 */
#[Route('/organization', name: 'app_organization_')]
#[IsGranted('ROLE_USER')]
final class OrganizationController extends AbstractController
{
    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, CreateOrganization $createOrganization): Response
    {
        $user = $this->requireUser();

        $form = $this->createForm(OrganizationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name: string} $data */
            $data = $form->getData();

            $organization = $createOrganization($user, $data['name']);

            // La organización nueva pasa a ser la activa: es la única que hay.
            $request->getSession()->set('active_organization_id', $organization->getId()->toRfc4122());

            $this->addFlash('success', 'Organización creada. Ya puedes empezar a registrar tus servicios.');

            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('identity/organization.html.twig', [
            'form' => $form,
        ]);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
