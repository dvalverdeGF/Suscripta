<?php

declare(strict_types=1);

namespace App\Tests\Support\Ai;

use App\Ai\Domain\Contract\AiProviderInterface;
use App\Ai\Domain\Enum\AiPurpose;
use App\Ai\Domain\Enum\AiTier;
use App\Ai\Domain\ValueObject\AiRequest;
use App\Ai\Domain\ValueObject\AiResponse;

use function count;

use Throwable;

/**
 * Proveedor de IA de mentira.
 *
 * Devuelve una respuesta preparada y guarda las peticiones que ha recibido,
 * para poder comprobar qué se mandó al modelo (el prompt, el redactado, los
 * datos deterministas) sin gastar un céntimo.
 */
final class FakeAiProvider implements AiProviderInterface
{
    /**
     * @var list<AiRequest>
     */
    private array $requests = [];

    /**
     * @param list<AiPurpose> $purposes
     */
    public function __construct(
        private readonly string $key = 'fake',
        private readonly AiTier $tier = AiTier::CHEAP,
        private readonly array $purposes = [AiPurpose::EXTRACTION],
        private readonly ?AiResponse $response = null,
        private readonly ?Throwable $failure = null,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return 'Proveedor de prueba';
    }

    public function tier(): AiTier
    {
        return $this->tier;
    }

    public function purposes(): array
    {
        return $this->purposes;
    }

    public function complete(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->response ?? new AiResponse(content: '', data: []);
    }

    /**
     * Peticiones recibidas, en orden.
     *
     * @return list<AiRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }

    public function lastRequest(): ?AiRequest
    {
        return $this->requests[count($this->requests) - 1] ?? null;
    }
}
