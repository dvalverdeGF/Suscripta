<?php

declare(strict_types=1);

namespace App\Billing\Infrastructure\Repository;

use App\Billing\Domain\Entity\PaymentEvent;
use App\Billing\Domain\Repository\PaymentEventRepositoryInterface;
use App\Shared\Infrastructure\Persistence\DoctrineRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Registro de eventos aplicados, con reserva atómica.
 *
 * ## La reserva va por DBAL y no por ORM
 *
 * Un `flush()` que choca con un índice único **cierra el `EntityManager`**:
 * Doctrine no puede garantizar el estado de la unidad de trabajo después de un
 * error así y lo invalida. Capturar la excepción y seguir trabajando dejaría la
 * petición viva con un gestor muerto, y el siguiente `persist()` fallaría con un
 * error que no tiene nada que ver con el evento duplicado.
 *
 * Por eso la reserva se hace con un `INSERT` directo: si choca, la conexión
 * sigue sana y el proceso puede responder «ya estaba» sin más.
 */
final class PaymentEventRepository extends DoctrineRepository implements PaymentEventRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager);
    }

    public function claim(string $externalEventId, string $type, DateTimeImmutable $occurredAt): bool
    {
        $connection = $this->entityManager->getConnection();

        // El `INSERT` va dentro de su propia transacción (o de un punto de
        // guardado, si ya hay una abierta) para que el choque con el índice
        // único se pueda deshacer sin arrastrar al resto: en PostgreSQL, una
        // sentencia que falla deja la transacción en aborto y la siguiente
        // consulta ya no se ejecuta.
        $connection->beginTransaction();

        try {
            $connection->insert('payment_event', [
                'id' => Uuid::v7()->toRfc4122(),
                'external_event_id' => $externalEventId,
                'type' => $type,
                'applied' => false,
                'note' => null,
                'created_at' => $occurredAt,
            ], [
                'id' => Types::STRING,
                // Sin el tipo explícito, DBAL enlaza todo como cadena y `false`
                // viaja como `''`, que PostgreSQL no acepta en una columna
                // booleana.
                'applied' => Types::BOOLEAN,
                'created_at' => Types::DATETIME_IMMUTABLE,
            ]);

            $connection->commit();

            return true;
        } catch (UniqueConstraintViolationException) {
            $connection->rollBack();

            return false;
        }
    }

    public function release(string $externalEventId): void
    {
        $this->entityManager->getConnection()->delete('payment_event', [
            'external_event_id' => $externalEventId,
        ]);
    }

    public function find(string $externalEventId): ?PaymentEvent
    {
        return $this->entityManager
            ->createQueryBuilder()
            ->select('e')
            ->from(PaymentEvent::class, 'e')
            ->where('e.externalEventId = :externalEventId')
            ->setParameter('externalEventId', $externalEventId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(PaymentEvent $event): void
    {
        // La fila ya existe —la acaba de reservar `claim` y `find` la ha
        // cargado—, así que el gestor la conoce y basta con volcar los
        // cambios. El `persist` es solo para una entidad que llegue suelta.
        if (!$this->entityManager->contains($event)) {
            $this->entityManager->persist($event);
        }

        $this->flush();
    }
}
