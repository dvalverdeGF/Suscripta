<?php

declare(strict_types=1);

namespace App\Processing\Domain\Provider;

use App\Mailbox\Application\Imap\ImapMessageHeader;

use function is_array;
use function is_string;

/**
 * Todo lo que un parser necesita para interpretar un documento
 * (ARCHITECTURE.md §13.7).
 *
 * Se pasa un objeto y no cinco argumentos porque la lista crecerá: cuando
 * lleguen los adjuntos hará falta el texto del PDF, y cuando llegue el
 * aprendizaje hará falta el histórico del buzón. Un DTO permite añadirlo sin
 * tocar cada parser.
 */
final readonly class ProviderParserContext
{
    /**
     * @param array<string, mixed> $config configuración declarativa del parser
     */
    public function __construct(
        public ImapMessageHeader $header,
        public string $bodyText,
        public string $providerName,
        public array $config = [],
    ) {
    }

    /**
     * Texto sobre el que buscar: asunto y cuerpo juntos.
     *
     * El asunto va primero a propósito. En muchas facturas el importe y el
     * periodo solo aparecen en el cuerpo, pero el número de factura y el plan
     * suelen estar en el asunto, y las expresiones regulares devuelven la
     * primera coincidencia.
     */
    public function haystack(): string
    {
        return $this->header->subject."\n".$this->bodyText;
    }

    public function configString(string $key, ?string $default = null): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && '' !== $value ? $value : $default;
    }

    /**
     * @return list<string>
     */
    public function configList(string $key): array
    {
        $value = $this->config[$key] ?? null;

        if (!is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            if (is_string($item) && '' !== $item) {
                $list[] = $item;
            }
        }

        return $list;
    }
}
