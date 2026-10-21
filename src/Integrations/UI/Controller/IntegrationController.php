<?php

declare(strict_types=1);

namespace App\Integrations\UI\Controller;

use App\Integrations\Application\IntegrationConfigReader;
use App\Integrations\Application\IntegrationConfigWriter;
use App\Integrations\Application\IntegrationDefinition;
use App\Integrations\Application\IntegrationRegistry;

use function is_string;
use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Panel de integraciones (Fase 11).
 *
 * ## Dos pantallas, no un formulario gigante
 *
 * El listado dice **el estado** de cada integración; la ficha la configura. Si
 * el listado fuera un formulario por fila, cambiar una credencial obligaría a
 * recargar la página entera y a jugglingear con el estado de las demás.
 *
 * ## Los nombres de los campos salen de la definición
 *
 * El formulario no tiene campos escritos a mano: se recorre
 * `definition.credentialFields` y `definition.settingsFields`. Consecuencia
 * práctica: un campo inventado en el POST **no se guarda**, porque el
 * controlador solo lee los nombres que la definición declara. Sin esto, un
 * POST manipulado podría meter ajustes arbitrarios en la configuración.
 *
 * ## El secreto guardado no vuelve al navegador
 *
 * El campo se pinta vacío y su estado se dice aparte («ya configurada»). El
 * operador no necesita leer su token: necesita saber que está puesto. Por eso
 * el valor guardado nunca aparece ni en el HTML ni en los logs.
 */
#[Route('/integrations', name: 'app_integrations_')]
#[IsGranted('ROLE_USER')]
final class IntegrationController extends AbstractController
{
    public function __construct(
        private readonly IntegrationRegistry $registry,
        private readonly IntegrationConfigReader $config,
        private readonly IntegrationConfigWriter $writer,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $rows = [];

        foreach ($this->registry->all() as $definition) {
            $rows[] = [
                'key' => $definition->key,
                'label' => $definition->label,
                'icon' => $definition->icon,
                'definition' => $definition,
                'integration' => $this->config->find($definition->key),
            ];
        }

        return $this->render('backoffice/integration/index.html.twig', [
            'rows' => $rows,
        ]);
    }

    #[Route('/{key}', name: 'edit', methods: ['GET', 'POST'], requirements: ['key' => '[a-z0-9_\-]+'])]
    public function edit(string $key, Request $request): Response
    {
        $definition = $this->registry->find($key);

        if (null === $definition) {
            throw $this->createNotFoundException('Esa integración no existe.');
        }

        $integration = $this->config->find($key);
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('save_integration_'.$key, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Token CSRF no válido.');
            }

            $errors = $this->collectErrors($definition, $request);

            if ([] === $errors) {
                $this->writer->save(
                    key: $key,
                    label: $definition->label,
                    baseUrl: $this->baseUrl($definition, $request),
                    credentials: $this->credentials($definition, $request),
                    settings: $this->settings($definition, $request),
                    enabled: $request->request->has('enabled'),
                );

                $this->addFlash('success', sprintf('La configuración de «%s» se ha guardado.', $definition->label));

                return $this->redirectToRoute('app_integrations_edit', ['key' => $key]);
            }

            $this->addFlash('error', 'No se ha guardado: revisa los campos marcados.');
        }

        $credentials = $this->config->credentials($key);

        return $this->render('backoffice/integration/edit.html.twig', [
            'definition' => $definition,
            'integration' => $this->config->find($key),
            'configuredCredentials' => array_keys($credentials),
            'configuredSettings' => $this->config->settings($key),
            'errors' => $errors,
        ]);
    }

    #[Route('/{key}/credentials/clear', name: 'clear_credentials', methods: ['POST'], requirements: ['key' => '[a-z0-9_\-]+'])]
    public function clearCredentials(string $key, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('clear_integration_credentials_'.$key, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        $definition = $this->registry->find($key);

        if (null === $definition) {
            throw $this->createNotFoundException('Esa integración no existe.');
        }

        $this->writer->clearCredentials($key);

        $this->addFlash('success', sprintf('Se han borrado las credenciales de «%s».', $definition->label));

        return $this->redirectToRoute('app_integrations_edit', ['key' => $key]);
    }

    /**
     * Errores de todos los campos, **todos a la vez**.
     *
     * Validar solo el primer campo que falla obliga al operador a guardar,
     * ver un error, corregirlo, guardar otra vez… y le hace perder la
     * impresión de que el formulario no funciona.
     *
     * @return array<string, string> nombre del campo → motivo
     */
    private function collectErrors(IntegrationDefinition $definition, Request $request): array
    {
        $errors = [];

        foreach ($definition->credentialFields as $field) {
            $error = $field->validate($request->request->get('credential_'.$field->name));

            if (null !== $error) {
                $errors['credential_'.$field->name] = $error;
            }
        }

        foreach ($definition->settingsFields as $field) {
            $error = $field->validate($request->request->get('setting_'.$field->name));

            if (null !== $error) {
                $errors['setting_'.$field->name] = $error;
            }
        }

        return $errors;
    }

    /**
     * Credenciales enviadas.
     *
     * Solo se leen los campos que la definición declara, y **solo los que
     * brought algo**: un campo en blanco significa «no lo he tocado» y lo
     * resuelve el escritor, que conserva lo que hubiera.
     *
     * @return array<string, string|null>
     */
    private function credentials(IntegrationDefinition $definition, Request $request): array
    {
        $credentials = [];

        foreach ($definition->credentialFields as $field) {
            $value = $request->request->get('credential_'.$field->name);
            $credentials[$field->name] = is_string($value) ? $value : null;
        }

        return $credentials;
    }

    /**
     * Ajustes enviados.
     *
     * @return array<string, string>
     */
    private function settings(IntegrationDefinition $definition, Request $request): array
    {
        $settings = [];

        foreach ($definition->settingsFields as $field) {
            $value = $request->request->get('setting_'.$field->name);

            if (!is_string($value) || '' === trim($value)) {
                continue;
            }

            $settings[$field->name] = trim($value);
        }

        return $settings;
    }

    /**
     * URL base, si la integración la necesita.
     */
    private function baseUrl(IntegrationDefinition $definition, Request $request): ?string
    {
        if (!$definition->requiresBaseUrl) {
            return null;
        }

        $value = $request->request->get('base_url');

        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
