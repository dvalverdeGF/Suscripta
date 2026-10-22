<?php

declare(strict_types=1);

namespace App\Discovery\UI\Controller;

use App\Catalog\Domain\Repository\CategoryRepositoryInterface;
use App\Catalog\Domain\Repository\ProviderRepositoryInterface;
use App\Discovery\Application\BatchReviewDiscoveries;
use App\Discovery\Application\ConfirmDiscovery;
use App\Discovery\Application\ConfirmInference;
use App\Discovery\Application\DismissDiscovery;
use App\Discovery\Domain\Entity\Discovery;
use App\Discovery\Domain\Enum\DiscoveryStatus;
use App\Discovery\Domain\Enum\DiscoveryType;
use App\Discovery\Domain\Repository\DiscoveryRepositoryInterface;
use App\Discovery\UI\Form\DiscoveryFormData;
use App\Discovery\UI\Form\DiscoveryFormType;
use App\Identity\Domain\Entity\User;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Services\Domain\Entity\Service;
use App\Services\Domain\Repository\ServiceViewRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\Currency;

use function array_filter;
use function array_values;
use function in_array;
use function is_string;
use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

use function trim;

/**
 * Bandeja de revisión de propuestas (ARCHITECTURE.md §13.11).
 *
 * Es la pantalla que cierra el bucle del producto: el sistema ha leído el
 * correo, ha deducido algo y aquí el usuario decide. Todo lo que se muestra
 * viene acompañado de **por qué** se propone —puntuación, señales y el correo
 * original—, porque una propuesta sin justificación es indistinguible de un
 * error.
 */
#[Route('/discoveries', name: 'app_discoveries_')]
#[IsGranted('ROLE_USER')]
final class DiscoveryController extends AbstractController
{
    /** Toda la bandeja, sin distinguir hechos de sugerencias. */
    private const SCOPE_ALL = 'all';

    /** Solo lo que el sistema ha leído en un documento. */
    private const SCOPE_FACTS = 'facts';

    /** Solo lo que el sistema ha deducido del inventario (D-59). */
    private const SCOPE_SUGGESTIONS = 'suggestions';

    public function __construct(
        private readonly DiscoveryRepositoryInterface $discoveries,
        private readonly EmailMessageRepositoryInterface $messages,
        private readonly ServiceViewRepositoryInterface $services,
        private readonly ProviderRepositoryInterface $providers,
        private readonly CategoryRepositoryInterface $categories,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = DiscoveryStatus::tryFrom((string) $request->query->get('status', '')) ?? DiscoveryStatus::PENDING;
        $type = DiscoveryType::tryFrom((string) $request->query->get('type', ''));
        $scope = $this->scope((string) $request->query->get('scope', ''));

        $discoveries = $this->discoveries->findForOrganization($status, 100, $type);
        $discoveries = array_values(array_filter(
            $discoveries,
            static fn (Discovery $discovery): bool => self::matchesScope($discovery, $scope),
        ));

        $sources = [];
        foreach ($discoveries as $discovery) {
            $messageId = $discovery->getSourceEmailMessageId();

            if (null !== $messageId) {
                $sources[$discovery->getId()->toRfc4122()] = $this->messages->find($messageId);
            }
        }

        return $this->render('discoveries/index.html.twig', [
            'discoveries' => $discoveries,
            'sources' => $sources,
            'status' => $status,
            'type' => $type,
            'scope' => $scope,
            'types' => DiscoveryType::cases(),
            'counts' => $this->discoveries->countByStatus(),
        ]);
    }

    /**
     * Revisión por lotes: el usuario marca varias propuestas y las acepta o
     * descarta de una vez.
     *
     * El lote confirma lo que el pipeline propuso, sin tocar los datos. La
     * revisión fina sigue estando en la pantalla de cada propuesta.
     */
    #[Route('/batch', name: 'batch', methods: ['POST'])]
    public function batch(Request $request, BatchReviewDiscoveries $batchReview): Response
    {
        if (!$this->isCsrfTokenValid('discovery_batch', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        $discoveries = $this->loadSelected($request);

        if ([] === $discoveries) {
            $this->addFlash('info', 'No has seleccionado ninguna propuesta.');

            return $this->redirectToRoute('app_discoveries_index');
        }

        $action = (string) $request->request->get('action', '');

        if ('confirm' === $action) {
            $result = $batchReview->confirm($discoveries, $this->actorId());
            $this->addFlash('success', sprintf(
                '%d propuesta(s) confirmada(s). %s',
                $result->confirmed,
                $result->failed > 0 ? sprintf('%d no se han podido confirmar.', $result->failed) : '',
            ));
        } elseif ('dismiss' === $action) {
            $result = $batchReview->dismiss($discoveries, null, $this->actorId());
            $this->addFlash('info', sprintf(
                '%d propuesta(s) descartada(s). %s',
                $result->dismissed,
                $result->failed > 0 ? sprintf('%d no se han podido descartar.', $result->failed) : '',
            ));
        } else {
            throw $this->createAccessDeniedException('Acción de lote no reconocida.');
        }

        return $this->redirectToRoute('app_discoveries_index');
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function show(Discovery $discovery): Response
    {
        $data = DiscoveryFormData::fromDiscovery($discovery);
        $form = $this->createForm(DiscoveryFormType::class, $data, $this->formOptions($data->currency ?? Currency::EUR));

        $messageId = $discovery->getSourceEmailMessageId();

        return $this->render('discoveries/show.html.twig', [
            'discovery' => $discovery,
            'form' => $form,
            'source' => null === $messageId ? null : $this->messages->find($messageId),
            'matchedService' => null === $discovery->getMatchedServiceId()
                ? null
                : $this->services->findOne($discovery->getMatchedServiceId()),
            'evidence' => $this->discoveries->findEvidence($discovery->getId()),
        ]);
    }

    #[Route('/{id}/confirm', name: 'confirm', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function confirm(Discovery $discovery, Request $request, ConfirmDiscovery $confirmDiscovery): Response
    {
        $data = DiscoveryFormData::fromDiscovery($discovery);
        $form = $this->createForm(DiscoveryFormType::class, $data, $this->formOptions($data->currency ?? Currency::EUR));
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $messageId = $discovery->getSourceEmailMessageId();

            return $this->render('discoveries/show.html.twig', [
                'discovery' => $discovery,
                'form' => $form,
                'source' => null === $messageId ? null : $this->messages->find($messageId),
                'matchedService' => null === $discovery->getMatchedServiceId()
                    ? null
                    : $this->services->findOne($discovery->getMatchedServiceId()),
                'evidence' => $this->discoveries->findEvidence($discovery->getId()),
            ]);
        }

        try {
            $service = $confirmDiscovery($discovery, $data->toInput(), $this->actorId());
        } catch (InvalidArgumentException $exception) {
            $form->addError(new FormError($exception->getMessage()));

            return $this->render('discoveries/show.html.twig', [
                'discovery' => $discovery,
                'form' => $form,
                'source' => null,
                'matchedService' => null,
                'evidence' => $this->discoveries->findEvidence($discovery->getId()),
            ]);
        }

        $this->addFlash('success', sprintf('«%s» ya forma parte de tus servicios.', $service->getName()));

        return $this->redirectToRoute('app_services_show', ['id' => $service->getId()->toRfc4122()]);
    }

    /**
     * Resolver una **inferencia** (D-59).
     *
     * Una inferencia no se confirma con el formulario de servicio: no hay
     * datos que revisar, hay una sospecha que aceptar o descartar. Por eso
     * tiene su propia ruta, con su propio token CSRF, y su propia aplicación
     * (`ConfirmInference`).
     */
    #[Route('/{id}/resolve', name: 'resolve', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function resolve(Discovery $discovery, Request $request, ConfirmInference $confirmInference): Response
    {
        if (!$this->isCsrfTokenValid('discovery_resolve_'.$discovery->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        if (!$discovery->getType()->isInference()) {
            $this->addFlash('error', 'Esta propuesta no es una inferencia: confírmala con el formulario de servicio.');

            return $this->redirectToRoute('app_discoveries_show', ['id' => $discovery->getId()->toRfc4122()]);
        }

        try {
            $service = $confirmInference($discovery, $this->actorId());
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_discoveries_show', ['id' => $discovery->getId()->toRfc4122()]);
        }

        $this->addFlash('success', $this->resolutionMessage($discovery, $service));

        return $this->redirectToRoute('app_discoveries_index');
    }

    #[Route('/{id}/dismiss', name: 'dismiss', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function dismiss(Discovery $discovery, Request $request, DismissDiscovery $dismissDiscovery): Response
    {
        if (!$this->isCsrfTokenValid('discovery_dismiss_'.$discovery->getId()->toRfc4122(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        $reason = trim((string) $request->request->get('reason', ''));

        try {
            $dismissDiscovery($discovery, '' === $reason ? null : $reason, $this->actorId());
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_discoveries_show', ['id' => $discovery->getId()->toRfc4122()]);
        }

        $this->addFlash('info', 'Propuesta descartada. El sistema lo tendrá en cuenta para no volver a proponerla.');

        return $this->redirectToRoute('app_discoveries_index');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Currency $currency): array
    {
        $organizationId = $this->tenantContext->requireOrganizationId();

        $providerChoices = [];
        foreach ($this->providers->findVisibleForOrganization($organizationId) as $provider) {
            $providerChoices[$provider->getName()] = $provider->getId();
        }

        $categoryChoices = [];
        foreach ($this->categories->findVisibleForOrganization($organizationId) as $category) {
            $categoryChoices[$category->getName()] = $category->getId();
        }

        return [
            'currency' => $currency,
            'provider_choices' => $providerChoices,
            'category_choices' => $categoryChoices,
        ];
    }

    /**
     * Propuestas marcadas en el formulario de lote.
     *
     * Los identificadores que no son UUID válidos se descartan en silencio: un
     * formulario manipulado no debe romper la pantalla, solo no debe hacer
     * nada con lo que no reconoce.
     *
     * @return list<Discovery>
     */
    private function loadSelected(Request $request): array
    {
        $ids = $request->request->all('discoveries');
        $discoveries = [];

        foreach ($ids as $id) {
            if (!is_string($id) || !Uuid::isValid($id)) {
                continue;
            }

            $discovery = $this->discoveries->find(Uuid::fromString($id));

            if (null !== $discovery) {
                $discoveries[] = $discovery;
            }
        }

        return $discoveries;
    }

    private function actorId(): ?Uuid
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getId() : null;
    }

    /**
     * El alcance de la bandeja: todo, solo hechos o solo sugerencias.
     *
     * Los hechos son los que se han leído en un documento; las sugerencias son
     * inferencias (D-59). Mezclarlos en la misma lista es justo lo que el
     * usuario quiere evitar, así que se pueden separar.
     */
    private function scope(string $value): string
    {
        return in_array($value, [self::SCOPE_FACTS, self::SCOPE_SUGGESTIONS], true) ? $value : self::SCOPE_ALL;
    }

    private static function matchesScope(Discovery $discovery, string $scope): bool
    {
        return match ($scope) {
            self::SCOPE_FACTS => !$discovery->isInference(),
            self::SCOPE_SUGGESTIONS => $discovery->isInference(),
            default => true,
        };
    }

    /**
     * El texto que explica qué ha pasado al aceptar una inferencia.
     */
    private function resolutionMessage(Discovery $discovery, ?Service $service): string
    {
        $name = null === $service ? '' : sprintf(' «%s»', $service->getName());

        return match ($discovery->getType()) {
            DiscoveryType::DUPLICATE => sprintf('Duplicado confirmado: se ha cancelado el servicio repetido%s.', $name),
            DiscoveryType::MISSING_CHARGE => sprintf('Cobro revisado%s: queda anotado y se ha adelantado la próxima fecha de cobro.', $name),
            DiscoveryType::DORMANT => sprintf('Servicio cancelado%s: ya no contará en tus costes.', $name),
            default => sprintf('Propuesta resuelta%s.', $name),
        };
    }
}
