<?php

declare(strict_types=1);

namespace App\Processing\Application\Knowledge;

use App\Discovery\Application\Dto\DiscoveryInput;
use App\Discovery\Domain\Entity\Discovery;
use App\Documents\Domain\Enum\DocumentType;
use App\Mailbox\Domain\Repository\EmailMessageRepositoryInterface;
use App\Processing\Application\Knowledge\Dto\KnowledgeObservation;
use App\Processing\Domain\Enum\MailboxKnowledgeSource;

use function is_string;

/**
 * Aprende de la confirmación del usuario (ARCHITECTURE.md §13.12).
 *
 * Cuando el usuario acepta una propuesta, está diciendo dos cosas a la vez:
 * «este remitente es este proveedor» y «este documento se cobra cada X». La
 * segunda es la que más IA ahorra, porque sin periodicidad el documento no es
 * accionable y el pipeline tiene que escalar (D-52).
 *
 * Lo que se aprende aquí es **autoritativo**: lo ha dicho el usuario, así que
 * pisa lo que el sistema hubiera deducido. Es la diferencia entre «lo creo yo»
 * y «lo creyó el sistema», y por eso la fuente queda marcada como
 * `CONFIRMATION`.
 *
 * Si la propuesta no viene de un correo (alta manual, importación) no hay nada
 * que aprender: el conocimiento del buzón es, por definición, sobre correos.
 */
final readonly class LearnFromConfirmation
{
    public function __construct(
        private EmailMessageRepositoryInterface $messages,
        private LearnFromMessage $learnFromMessage,
    ) {
    }

    public function __invoke(Discovery $discovery, DiscoveryInput $input): void
    {
        $messageId = $discovery->getSourceEmailMessageId();

        if (null === $messageId) {
            return;
        }

        $message = $this->messages->find($messageId);

        if (null === $message) {
            return;
        }

        $sender = $message->getFromAddress();

        if (null === $sender || '' === $sender) {
            return;
        }

        $observation = new KnowledgeObservation(
            emailAccountId: $message->getEmailAccountId(),
            organizationId: $message->getOrganizationId(),
            sender: $sender,
            senderDomain: $message->getSenderDomain(),
            subject: $message->getSubject(),
            attachmentNames: $message->getAttachmentNames(),
            providerId: $input->providerId,
            documentType: $this->documentType($discovery),
            billingPeriod: $input->billingPeriod,
            source: MailboxKnowledgeSource::CONFIRMATION,
            confidence: MailboxKnowledgeSource::CONFIRMATION->defaultConfidence(),
        );

        ($this->learnFromMessage)($observation);
    }

    /**
     * El tipo de documento de la propuesta original.
     *
     * El usuario confirma servicios, no documentos: al confirmar no está
     * reetiquetando el tipo de documento, solo el proveedor y el precio. Por eso
     * se recupera de lo que el pipeline propuso en su día.
     */
    private function documentType(Discovery $discovery): ?DocumentType
    {
        $proposed = $discovery->getProposedData();
        $value = $proposed['documentType'] ?? null;

        if (!is_string($value)) {
            return null;
        }

        return DocumentType::tryFrom($value);
    }
}
