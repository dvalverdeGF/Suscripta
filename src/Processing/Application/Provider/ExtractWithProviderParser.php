<?php

declare(strict_types=1);

namespace App\Processing\Application\Provider;

use App\Catalog\Domain\Entity\ProviderParser;
use App\Catalog\Domain\Repository\ProviderParserRepositoryInterface;
use App\Mailbox\Application\Imap\ImapMessageHeader;
use App\Processing\Domain\Provider\ProviderMatch;
use App\Processing\Domain\Provider\ProviderParseResult;
use App\Processing\Domain\Provider\ProviderParserContext;
use App\Shared\Application\Clock;

/**
 * Nivel 4 del pipeline: extracción con el parser del proveedor
 * (ARCHITECTURE.md §13.7).
 *
 * Es la pieza que hace que el producto sea sostenible: una vez que un proveedor
 * es conocido, **sus facturas futuras no vuelven a costar IA**. La primera
 * factura puede necesitarla para entender la plantilla; a partir de ahí, el
 * conocimiento vive en `ProviderParser` y se aplica sin salir del sistema.
 *
 * Dos decisiones que importan:
 *
 * 1. **Un parser que no reconoce el documento no es un fallo.** Un proveedor
 *    puede tener dos plantillas vivas (la antigua y la nueva) y el pipeline
 *    prueba las dos. Solo se anota el fallo si **ninguna** ha acertado, que es
 *    cuando de verdad hay un problema.
 * 2. **Un parser que falla no rompe el lote.** Devuelve `null`, el pipeline
 *    sigue con el extractor genérico y, si hace falta, con la IA. Tras cinco
 *    fallos sin ningún acierto el parser se desactiva solo: es más honesto
 *    dejar de intentarlo que gastar en cada factura futura.
 */
final readonly class ExtractWithProviderParser
{
    public function __construct(
        private ProviderParserRepositoryInterface $parsers,
        private ProviderParserRegistry $registry,
        private Clock $clock,
    ) {
    }

    public function __invoke(ImapMessageHeader $header, ProviderMatch $match, string $bodyText = ''): ?ProviderParseResult
    {
        $provider = $match->provider;

        if (null === $provider) {
            return null;
        }

        $rows = $this->parsers->findEnabledForProvider($provider->getId());

        if ([] === $rows) {
            return null;
        }

        /** @var list<ProviderParser> $failed */
        $failed = [];

        foreach ($rows as $row) {
            $parser = $this->registry->get($row->getKey());

            if (null === $parser) {
                // La clave apunta a un parser que ya no existe en el código.
                // No es un fallo del parser, es una fila huérfana: se ignora.
                continue;
            }

            $result = $parser->parse(new ProviderParserContext(
                header: $header,
                bodyText: $bodyText,
                providerName: $provider->getName(),
                config: $row->getConfig(),
            ));

            if (null === $result || !$result->hasData()) {
                $failed[] = $row;

                continue;
            }

            $row->recordSuccess($this->clock->now());
            $this->parsers->save($row, false);

            return $result;
        }

        foreach ($failed as $row) {
            $row->recordFailure($this->clock->now());
            $this->parsers->save($row, false);
        }

        return null;
    }
}
