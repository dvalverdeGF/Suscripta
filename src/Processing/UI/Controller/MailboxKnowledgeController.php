<?php

declare(strict_types=1);

namespace App\Processing\UI\Controller;

use App\Catalog\Domain\Repository\ProviderRepositoryInterface;
use App\Documents\Domain\Enum\DocumentType;
use App\Mailbox\Domain\Entity\EmailAccount;
use App\Processing\Application\Knowledge\AddMailboxKnowledge;
use App\Processing\Application\Knowledge\DeleteMailboxKnowledge;
use App\Processing\Application\Knowledge\Dto\KnowledgeEdit;
use App\Processing\Application\Knowledge\ListMailboxKnowledge;
use App\Processing\Application\Knowledge\PromoteMailboxKnowledge;
use App\Processing\Application\Knowledge\UpdateMailboxKnowledge;
use App\Processing\Domain\Entity\MailboxKnowledgeEntry;
use App\Processing\Domain\Enum\MailboxKnowledgeType;
use App\Processing\Domain\Repository\MailboxKnowledgeRepositoryInterface;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\BillingPeriod;

use function count;
use function is_string;
use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Lo que el sistema ha aprendido de un buzón (ARCHITECTURE.md §13.12).
 *
 * La pantalla no es un panel de configuración: es la lista de afirmaciones que
 * el sistema hace sobre el correo del usuario, y cada una se puede corregir,
 * borrar o promover al catálogo global.
 *
 * **Promover es explícito a propósito.** El conocimiento del buzón es privado;
 * el catálogo global es de todos. Por eso el botón de promover existe, pero no
 * hay ninguna promoción automática (D-33).
 */
#[Route('/mail/accounts/{id}/knowledge', name: 'app_mail_knowledge_', requirements: ['id' => '[0-9a-fA-F-]{36}'])]
#[IsGranted('ROLE_USER')]
final class MailboxKnowledgeController extends AbstractController
{
    public function __construct(
        private readonly MailboxKnowledgeRepositoryInterface $entries,
        private readonly ListMailboxKnowledge $listKnowledge,
        private readonly ProviderRepositoryInterface $providers,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(EmailAccount $account): Response
    {
        $grouped = ($this->listKnowledge)($account->getId());
        $providerNames = [];

        foreach ($this->providers->findVisibleForOrganization($account->getOrganizationId()) as $provider) {
            $providerNames[$provider->getId()->toRfc4122()] = $provider;
        }

        return $this->render('mail/accounts/knowledge.html.twig', [
            'account' => $account,
            'grouped' => $grouped,
            'providerNames' => $providerNames,
            'types' => MailboxKnowledgeType::cases(),
            'documentTypes' => DocumentType::cases(),
            'billingPeriods' => BillingPeriod::cases(),
            'total' => count($this->listKnowledge->all($account->getId())),
        ]);
    }

    /**
     * Añade una regla escrita a mano.
     */
    #[Route('/add', name: 'add', methods: ['POST'])]
    public function add(EmailAccount $account, Request $request, AddMailboxKnowledge $addKnowledge): Response
    {
        $this->assertCsrf($request, 'mail_knowledge_add_'.$account->getId()->toRfc4122());

        $type = MailboxKnowledgeType::tryFrom((string) $request->request->get('type'));
        $value = trim((string) $request->request->get('value'));

        if (null === $type || '' === $value) {
            $this->addFlash('error', 'Necesitamos el tipo de regla y el valor.');

            return $this->redirectToRoute('app_mail_knowledge_index', ['id' => $account->getId()->toRfc4122()]);
        }

        [$defaultDocumentType, $defaultPeriod] = AddMailboxKnowledge::defaultsFor($type);

        try {
            $addKnowledge(
                $account->getId(),
                $account->getOrganizationId(),
                $type,
                new KnowledgeEdit(
                    value: $value,
                    providerId: $this->providerId($request),
                    documentType: $this->documentType($request) ?? $defaultDocumentType,
                    billingPeriod: $this->billingPeriod($request) ?? $defaultPeriod,
                    confidence: 100,
                ),
            );
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_mail_knowledge_index', ['id' => $account->getId()->toRfc4122()]);
        }

        $this->addFlash('success', 'Regla añadida. A partir de ahora la aplicamos a los correos de este buzón.');

        return $this->redirectToRoute('app_mail_knowledge_index', ['id' => $account->getId()->toRfc4122()]);
    }

    /**
     * Corrige una regla aprendida.
     */
    #[Route('/{entryId}/edit', name: 'edit', methods: ['POST'], requirements: ['entryId' => '[0-9a-fA-F-]{36}'])]
    public function edit(EmailAccount $account, Request $request, Uuid $entryId, UpdateMailboxKnowledge $updateKnowledge): Response
    {
        $this->assertCsrf($request, 'mail_knowledge_edit_'.$entryId->toRfc4122());

        $entry = $this->entry($account, $entryId);

        if (null === $entry) {
            $this->addFlash('error', 'No hemos encontrado esa regla.');

            return $this->redirectToRoute('app_mail_knowledge_index', ['id' => $account->getId()->toRfc4122()]);
        }

        $value = trim((string) $request->request->get('value'));

        if ('' === $value) {
            $this->addFlash('error', 'El valor no puede estar vacío.');

            return $this->redirectToRoute('app_mail_knowledge_index', ['id' => $account->getId()->toRfc4122()]);
        }

        $updateKnowledge($entry, new KnowledgeEdit(
            value: $value,
            providerId: $this->providerId($request),
            documentType: $this->documentType($request),
            billingPeriod: $this->billingPeriod($request),
            confidence: $this->confidence($request),
        ));

        $this->addFlash('success', 'Regla actualizada.');

        return $this->redirectToRoute('app_mail_knowledge_index', ['id' => $account->getId()->toRfc4122()]);
    }

    /**
     * Borra una regla.
     *
     * Para un remitente ignorado, borrar la regla es la forma de dejar de
     * ignorarlo: no hay un interruptor aparte, porque «no lo quiero» y «ya no
     * lo quiero» son el mismo botón en momentos distintos.
     */
    #[Route('/{entryId}/delete', name: 'delete', methods: ['POST'], requirements: ['entryId' => '[0-9a-fA-F-]{36}'])]
    public function delete(EmailAccount $account, Request $request, Uuid $entryId, DeleteMailboxKnowledge $deleteKnowledge): Response
    {
        $this->assertCsrf($request, 'mail_knowledge_delete_'.$entryId->toRfc4122());

        $entry = $this->entry($account, $entryId);

        if (null === $entry) {
            $this->addFlash('error', 'No hemos encontrado esa regla.');

            return $this->redirectToRoute('app_mail_knowledge_index', ['id' => $account->getId()->toRfc4122()]);
        }

        $wasIgnored = $entry->getType()->isExclusion();
        $deleteKnowledge($entry);

        $this->addFlash('success', $wasIgnored
            ? 'Ya no ignoramos a ese remitente.'
            : 'Regla eliminada.');

        return $this->redirectToRoute('app_mail_knowledge_index', ['id' => $account->getId()->toRfc4122()]);
    }

    /**
     * Ignora a un remitente, a petición del usuario.
     */
    #[Route('/{entryId}/ignore', name: 'ignore', methods: ['POST'], requirements: ['entryId' => '[0-9a-fA-F-]{36}'])]
    public function ignore(EmailAccount $account, Request $request, Uuid $entryId, AddMailboxKnowledge $addKnowledge): Response
    {
        $this->assertCsrf($request, 'mail_knowledge_ignore_'.$entryId->toRfc4122());

        $entry = $this->entry($account, $entryId);

        if (null === $entry) {
            $this->addFlash('error', 'No hemos encontrado esa regla.');

            return $this->redirectToRoute('app_mail_knowledge_index', ['id' => $account->getId()->toRfc4122()]);
        }

        $addKnowledge->ignoreSender(
            $account->getId(),
            $account->getOrganizationId(),
            $entry->getValue(),
        );

        $this->addFlash('success', sprintf('Ya no miramos los correos de %s.', $entry->getValue()));

        return $this->redirectToRoute('app_mail_knowledge_index', ['id' => $account->getId()->toRfc4122()]);
    }

    /**
     * Promueve lo aprendido al catálogo global.
     *
     * Es la única vía por la que un patrón de un buzón pasa a afectar a otros
     * usuarios, y por eso es una acción explícita y con su propio botón.
     */
    #[Route('/{entryId}/promote', name: 'promote', methods: ['POST'], requirements: ['entryId' => '[0-9a-fA-F-]{36}'])]
    public function promote(EmailAccount $account, Request $request, Uuid $entryId, PromoteMailboxKnowledge $promoteKnowledge): Response
    {
        $this->assertCsrf($request, 'mail_knowledge_promote_'.$entryId->toRfc4122());

        $entry = $this->entry($account, $entryId);

        if (null === $entry) {
            $this->addFlash('error', 'No hemos encontrado esa regla.');

            return $this->redirectToRoute('app_mail_knowledge_index', ['id' => $account->getId()->toRfc4122()]);
        }

        try {
            $promoteKnowledge($entry);
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_mail_knowledge_index', ['id' => $account->getId()->toRfc4122()]);
        }

        $this->addFlash('success', 'Regla promovida al catálogo global. A partir de ahora la usan todos los buzones.');

        return $this->redirectToRoute('app_mail_knowledge_index', ['id' => $account->getId()->toRfc4122()]);
    }

    /**
     * La entrada, pero solo si pertenece a este buzón.
     *
     * Sin esta comprobación, un identificador de otro buzón de la misma
     * organización se podría editar desde esta pantalla.
     */
    private function entry(EmailAccount $account, Uuid $entryId): ?MailboxKnowledgeEntry
    {
        $entry = $this->entries->find($entryId);

        if (null === $entry) {
            return null;
        }

        return $entry->getEmailAccountId()->equals($account->getId()) ? $entry : null;
    }

    private function providerId(Request $request): ?Uuid
    {
        $value = (string) $request->request->get('providerId');

        if ('' === $value) {
            return null;
        }

        return Uuid::isValid($value) ? Uuid::fromString($value) : null;
    }

    private function documentType(Request $request): ?DocumentType
    {
        $value = (string) $request->request->get('documentType');

        if ('' === $value) {
            return null;
        }

        return DocumentType::tryFrom($value);
    }

    private function billingPeriod(Request $request): ?BillingPeriod
    {
        $value = (string) $request->request->get('billingPeriod');

        if ('' === $value) {
            return null;
        }

        return BillingPeriod::tryFrom($value);
    }

    private function confidence(Request $request): int
    {
        $value = $request->request->get('confidence');

        if (!is_string($value) || '' === $value) {
            return 100;
        }

        return max(0, min(100, (int) $value));
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }
    }
}
