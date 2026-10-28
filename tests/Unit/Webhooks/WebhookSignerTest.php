<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhooks;

use App\Webhooks\Domain\Service\WebhookSigner;
use PHPUnit\Framework\TestCase;

use function sprintf;
use function strlen;

/**
 * La firma de las entregas (Fase 20).
 *
 * Lo que se prueba no es que el HMAC «funcione» —eso lo hace PHP— sino las dos
 * decisiones que hacen que la firma sirva para algo: que el **timestamp entre
 * en lo firmado** y que la comparación sea estricta. Un firmante que firme solo
 * el cuerpo parece correcto hasta que alguien reenvía una petición capturada
 * meses después.
 */
final class WebhookSignerTest extends TestCase
{
    private const string SECRET = 'whsec_c2VjcmV0by1kZS1wcnVlYmE';

    public function testTheSignatureCoversTheTimestampAndTheBody(): void
    {
        $signer = new WebhookSigner();
        $body = '{"event":"service.created"}';

        $first = $signer->sign(self::SECRET, 1_759_000_000, $body);
        $sameInstantOtherBody = $signer->sign(self::SECRET, 1_759_000_000, '{"event":"charge.detected"}');
        $otherInstantSameBody = $signer->sign(self::SECRET, 1_759_000_001, $body);

        self::assertSame($first, $signer->sign(self::SECRET, 1_759_000_000, $body));
        self::assertNotSame($first, $sameInstantOtherBody);
        self::assertNotSame($first, $otherInstantSameBody);
        self::assertSame(64, strlen($first), 'HMAC-SHA-256 en hexadecimal son 64 caracteres.');
    }

    public function testTheHeaderCarriesTheTimestampAndTheSignature(): void
    {
        $signer = new WebhookSigner();

        $header = $signer->header(self::SECRET, 1_759_000_000, 'cuerpo');

        self::assertSame(
            sprintf('t=1759000000,v1=%s', $signer->sign(self::SECRET, 1_759_000_000, 'cuerpo')),
            $header,
        );
    }

    public function testAValidSignatureIsAccepted(): void
    {
        $signer = new WebhookSigner();
        $body = '{"id":"0199"}';
        $header = $signer->header(self::SECRET, 1_759_000_000, $body);

        self::assertTrue($signer->verify(self::SECRET, $header, $body, 1_759_000_010));
    }

    public function testAStaleDeliveryIsRejectedEvenWithAValidSignature(): void
    {
        $signer = new WebhookSigner();
        $body = '{"id":"0199"}';
        $header = $signer->header(self::SECRET, 1_759_000_000, $body);

        // Diez minutos después: fuera de la tolerancia de cinco.
        self::assertFalse($signer->verify(self::SECRET, $header, $body, 1_759_000_600));
    }

    public function testAnotherSecretIsRejected(): void
    {
        $signer = new WebhookSigner();
        $body = '{"id":"0199"}';
        $header = $signer->header(self::SECRET, 1_759_000_000, $body);

        self::assertFalse($signer->verify('whsec_otro', $header, $body, 1_759_000_000));
    }

    public function testAMalformedHeaderIsRejectedInsteadOfCrashing(): void
    {
        $signer = new WebhookSigner();

        self::assertFalse($signer->verify(self::SECRET, '', 'cuerpo', 1_759_000_000));
        self::assertFalse($signer->verify(self::SECRET, 'v1=abc', 'cuerpo', 1_759_000_000));
        self::assertFalse($signer->verify(self::SECRET, 't=1759000000', 'cuerpo', 1_759_000_000));
    }
}
