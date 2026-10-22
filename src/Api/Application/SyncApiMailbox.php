<?php

declare(strict_types=1);

namespace App\Api\Application;

use App\Api\Application\ReadModel\ResourceMapper;
use App\Api\Domain\Exception\ResourceNotFoundException;
use App\Mailbox\Application\SyncEmailAccount;
use App\Mailbox\Domain\Repository\EmailAccountRepositoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Sincroniza un buzón desde la API.
 *
 * ## Qué devuelve cuando la sincronización falla
 *
 * Un `run` con estado `failed` y su error. La petición **sí** se ejecutó: lo
 * que falló fue la conexión con el servidor de correo, que no es culpa de
 * quien llama. Devolver un `502` obligaría al integrador a distinguir «el
 * buzón no existe» de «el buzón existe pero el servidor de correo no
 * responde», y el segundo caso no es un error de la API: es un estado del
 * mundo. El integrador mira el `status` del `run` y decide.
 *
 * ## El buzón ajeno no existe
 *
 * La búsqueda de la cuenta pasa por el filtro de inquilino: una cuenta de
 * otra organización es un `404`, no un `403`. Al integrador no le hace falta
 * saber que ese buzón existe en algún sitio.
 */
final readonly class SyncApiMailbox
{
    public function __construct(
        private EmailAccountRepositoryInterface $accounts,
        private SyncEmailAccount $syncEmailAccount,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Uuid $id, ?int $months = null, ?int $limit = null): array
    {
        $account = $this->accounts->find($id);

        if (null === $account) {
            throw ResourceNotFoundException::of('el buzón');
        }

        $run = ($this->syncEmailAccount)(
            $account,
            null,
            $months ?? SyncEmailAccount::DEFAULT_MONTHS,
            $limit ?? SyncEmailAccount::DEFAULT_LIMIT,
        );

        return ResourceMapper::syncRun($run);
    }
}
