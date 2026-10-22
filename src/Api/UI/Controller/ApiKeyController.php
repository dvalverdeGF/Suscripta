<?php

declare(strict_types=1);

namespace App\Api\UI\Controller;

use App\Api\Application\Dto\ApiKeyInput;
use App\Api\Application\IssueApiKey;
use App\Api\Application\RevokeApiKey;
use App\Api\Domain\Enum\ApiScope;
use App\Api\Domain\Repository\ApiKeyRepositoryInterface;
use App\Api\UI\Form\ApiKeyFormData;
use App\Api\UI\Form\ApiKeyFormType;
use App\Identity\Domain\Entity\User;
use App\Shared\Application\Clock;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Exception\InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

use function is_array;
use function sprintf;

/**
 * Gestión de las claves de API desde el backoffice (Fase 18).
 *
 * ## Por qué el panel y no la API
 *
 * Una clave de API da acceso a la API. Si la API pudiera crear claves de API,
 * bastaría con que se filtrase **una** clave de escritura para obtener
 * acceso permanente y sin límite de uso. El panel exige sesión de usuario, así
 * que crear claves sigue siendo una decisión de una persona concreta.
 *
 * ## La clave en claro se ve una sola vez
 *
 * En la base de datos solo está su huella (HMAC). El texto en claro se le
 * enseña al operador **en el momento de crearla** y no vuelve a existir: ni
 * en la base de datos, ni en los logs, ni en un correo de «te la HAS_olvidado».
 *
 * Si se pierde, se emite otra y se revoca la perdida. Es la única respuesta
 * posible sin guardar la credencial en texto plano, y guardar una credencial
 * en texto plano es exactamente lo que no se debe hacer.
 *
 * ## La revocación es irreversible
 *
 * Revocar corta el acceso de golpe y no se puede deshacer: por eso el botón
 * pide confirmación. Es la operación que se usa cuando una clave se ha
 * filtrado, y en ese momento la inmediatez vale más que la reversibilidad.
 */
#[Route('/api-keys', name: 'app_api_keys_')]
#[IsGranted('ROLE_USER')]
final class ApiKeyController extends AbstractController
{
    /**
     * Clave de sesión donde se guarda la clave en claro recién creada.
     *
     * Vive en la sesión, no en un flash: un flash se borra al leerlo, y
     * aquí interesa que se pueda volver a la pantalla un momento después
     * (por ejemplo, si el técnico abre las herramientas de red y pierde la
     * pestaña). Aun así, se borra en cuanto se lee.
     */
    private const SESSION_SECRET = 'api_key.plain_secret';

    public function __construct(
        private readonly ApiKeyRepositoryInterface $apiKeys,
        private readonly IssueApiKey $issue,
        private readonly RevokeApiKey $revoke,
        private readonly TenantContext $tenantContext,
        private readonly Clock $clock,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $organizationId = $this->tenantContext->requireOrganizationId();

        $keys = $this->apiKeys->findForOrganization($organizationId);
        $now = $this->clock->now();

        return $this->render('backoffice/api_keys/index.html.twig', [
            'keys' => $keys,
            'now' => $now,
            'scopes' => ApiScope::cases(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = new ApiKeyFormData();
        $form = $this->createForm(ApiKeyFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $scopes = $data->toScopes();

            if ([] === $scopes) {
                // El campo es obligatorio, pero el mensaje se cuelga del
                // formulario para que se vea junto a las casillas y no en un
                // aviso que se pasa por alto.
                $form->get('scopes')->addError(new FormError('La clave necesita al menos un permiso.'));
            } else {
                try {
                    $issued = ($this->issue)(
                        new ApiKeyInput(
                            name: (string) $data->name,
                            scopes: $scopes,
                            expiresInDays: $data->expiresInDays,
                        ),
                        $this->actorId(),
                    );
                } catch (InvalidArgumentException $exception) {
                    $form->addError(new FormError($exception->getMessage()));

                    return $this->render('backoffice/api_keys/new.html.twig', [
                        'form' => $form,
                    ]);
                }

                // La clave en claro viaja en la sesión hasta la pantalla
                // siguiente, donde se enseña y se borra.
                $request->getSession()->set(self::SESSION_SECRET, [
                    'secret' => $issued['secret'],
                    'name' => $issued['key']->getName(),
                ]);

                $this->addFlash('success', sprintf('La clave «%s» se ha creado.', $issued['key']->getName()));

                return $this->redirectToRoute('app_api_keys_created');
            }
        }

        return $this->render('backoffice/api_keys/new.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Pantalla de la clave recién creada.
     *
     * Es la **única** vez que la clave en claro se ve. Al entrar, se borra de
     * la sesión: si el operador recarga la página, ya no está. Esa es la
     * diferencia entre una credencial y un archivo de texto.
     */
    #[Route('/created', name: 'created', methods: ['GET'])]
    public function created(Request $request): Response
    {
        $session = $request->getSession();
        $issued = $session->get(self::SESSION_SECRET);

        if (!is_array($issued) || !isset($issued['secret'], $issued['name'])) {
            $this->addFlash('error', 'Esa clave ya no está disponible: las claves en claro solo se muestran una vez. Si la has perdido, crea otra y revoca esta.');

            return $this->redirectToRoute('app_api_keys_index');
        }

        $session->remove(self::SESSION_SECRET);

        return $this->render('backoffice/api_keys/created.html.twig', [
            'secret' => (string) $issued['secret'],
            'name' => (string) $issued['name'],
        ]);
    }

    #[Route('/{id}/revoke', name: 'revoke', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function revoke(Request $request, Uuid $id): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('revoke_api_key', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        try {
            $key = ($this->revoke)($id);
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_api_keys_index');
        }

        $this->addFlash('success', sprintf('La clave «%s» ya no da acceso a la API.', $key->getName()));

        return $this->redirectToRoute('app_api_keys_index');
    }

    /**
     * El usuario que ha creado la clave, para poder auditar quién dio acceso.
     */
    private function actorId(): ?Uuid
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getId() : null;
    }
}
