<?php

declare(strict_types=1);

namespace App\Integrations\Application;

use App\Integrations\Domain\Entity\Integration;
use App\Integrations\Domain\Repository\IntegrationRepositoryInterface;
use App\Mailbox\Application\CredentialCipherInterface;
use App\Shared\Application\TenantContext;

use function array_filter;
use function array_merge;
use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

use Psr\Log\LoggerInterface;
use Throwable;

use function trim;

/**
 * Alta y edición de la configuración de integraciones externas.
 *
 * ## El secreto entra en claro y se guarda cifrado
 *
 * Este es el **único** sitio donde una credencial está en claro y se persiste,
 * y por eso concentra la responsabilidad: cifrar siempre con el proveedor
 * activo, guardar con qué clave se hizo, y no dejar que el texto plano salga de
 * aquí.
 *
 * ## Dejar el secreto en blanco **no** lo borra
 *
 * Es la decisión de diseño que más importa en un formulario de credenciales.
 * Al editar, el campo del token se pinta vacío (no se puede devolver el
 * secreto al navegador), así que "vacío" es ambiguo: puede ser "no lo he
 * tocado" o "quiero quitarlo".
 *
 * Se interpreta como **no tocado**. Al revés, guardar una pantalla cualquiera
 * por cualquier otro motivo borraría el token y dejaría la integración muerta
 * sin que nadie lo haya pedido. Para quitarlo hay una acción explícita.
 */
final readonly class IntegrationConfigWriter
{
    public function __construct(
        private IntegrationRepositoryInterface $repository,
        private CredentialCipherInterface $credentialCipher,
        private TenantContext $tenantContext,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Guarda la configuración de una integración.
     *
     * @param array<string, string|null> $credentials credenciales en claro; un valor
     *                                                `null` **conserva** la que
     *                                                hubiera
     * @param array<string, mixed>       $settings
     */
    public function save(
        string $key,
        string $label,
        ?string $baseUrl,
        array $credentials,
        array $settings,
        bool $enabled,
    ): Integration {
        $integration = $this->repository->find($key);

        if (null === $integration) {
            $integration = new Integration($this->tenantContext->requireOrganizationId(), $key, $label);
        } else {
            $integration->setLabel($label);
        }

        $integration->setBaseUrl($baseUrl);
        $integration->setSettings($settings);
        $integration->setEnabled($enabled);

        $this->applyCredentials($integration, $credentials);
        $this->repository->save($integration, true);

        $this->logger->info('Configuración de integración guardada.', [
            'integration' => $key,
            'enabled' => $enabled,
            // Nunca se registra ningún valor de credencial, solo si hay o no.
            'has_credentials' => $integration->hasCredentials(),
        ]);

        return $integration;
    }

    /**
     * Borra las credenciales de una integración.
     *
     * Acción aparte y explícita, para que quitarlas sea una decisión y no el
     * efecto colateral de dejar un campo en blanco.
     */
    public function clearCredentials(string $key): void
    {
        $integration = $this->repository->find($key);

        if (null === $integration) {
            return;
        }

        $integration->setCredentialsEncrypted(null, null);
        $this->repository->save($integration, true);

        $this->logger->warning('Credenciales de integración borradas.', [
            'integration' => $key,
        ]);
    }

    /**
     * @param array<string, string|null> $credentials
     */
    private function applyCredentials(Integration $integration, array $credentials): void
    {
        // Un `null` significa "no lo he tocado": se conserva lo que hubiera. Es lo
        // que llega cuando el formulario se envía sin reescribir el secreto.
        $provided = array_filter(
            $credentials,
            static fn (mixed $value): bool => null !== $value && '' !== trim((string) $value),
        );

        if ([] === $provided) {
            return;
        }

        // Al editar puede venir solo una parte: se **mezclan** con las que ya hay
        // para no perder las que el usuario no ha tocado. Se descifran para poder
        // recomponer el conjunto, y ese texto plano se va con esta llamada: no se
        // guarda ni se registra.
        $existing = $this->readExisting($integration);
        $merged = array_merge($existing, array_map(trim(...), $provided));

        $encrypted = $this->credentialCipher->encrypt((string) json_encode($merged, JSON_THROW_ON_ERROR));

        $integration->setCredentialsEncrypted($encrypted, null);
    }

    /**
     * Credenciales ya guardadas, descifradas.
     *
     * @return array<string, string>
     */
    private function readExisting(Integration $integration): array
    {
        $encrypted = $integration->getCredentialsEncrypted();

        if (null === $encrypted || '' === $encrypted) {
            return [];
        }

        try {
            $decoded = json_decode($this->credentialCipher->decrypt($encrypted), true);
        } catch (Throwable) {
            // Si lo guardado no se puede leer (clave rotada, texto corrupto), se
            // parte de vacío en lugar de fallar: el usuario está reescribiendo las
            // credenciales, que es justo el caso en el que hay que poder hacerlo.
            return [];
        }

        return is_array($decoded) ? array_filter($decoded, is_string(...)) : [];
    }
}
