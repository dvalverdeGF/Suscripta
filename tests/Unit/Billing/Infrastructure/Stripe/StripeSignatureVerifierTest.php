<?php

declare(strict_types=1);

namespace App\Tests\Unit\Billing\Infrastructure\Stripe;

use App\Billing\Domain\Exception\InvalidSignatureException;
use App\Billing\Infrastructure\Stripe\StripeSignatureVerifier;

use function hash_hmac;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function sprintf;
use function time;

/**
 * La firma del webhook es la única puerta del endpoint público (Fase 24).
 *
 * Lo que se prueba aquí es exactamente lo que separa un aviso de Stripe de un
 * `curl` con `{"type":"invoice.paid"}`: que el HMAC cuadre, que la marca de
 * tiempo no sea vieja y que la cabecera se pueda leer. Si esto falla, cualquiera
 * activa un plan gratis; por eso tiene test propio y no se prueba de refilón en
 * el funcional del webhook.
 */
#[CoversClass(StripeSignatureVerifier::class)]
final class StripeSignatureVerifierTest extends TestCase
{
    private const string SECRET = 'whsec_secreto_de_prueba';

    private const string PAYLOAD = '{"id":"evt_1","type":"invoice.paid"}';

    private StripeSignatureVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new StripeSignatureVerifier(tolerance: 300);
    }

    public function testItAcceptsASignatureMadeWithTheRightSecret(): void
    {
        $this->verifier->verify(self::PAYLOAD, $this->header(), self::SECRET);

        $this->expectNotToPerformAssertions();
    }

    public function testItRejectsASignatureMadeWithAnotherSecret(): void
    {
        $this->expectException(InvalidSignatureException::class);
        $this->expectExceptionMessage('no corresponde al cuerpo');

        $this->verifier->verify(self::PAYLOAD, $this->header(secret: 'whsec_otro'), self::SECRET);
    }

    public function testItRejectsABodyThatChangedAfterSigning(): void
    {
        $this->expectException(InvalidSignatureException::class);

        $this->verifier->verify('{"id":"evt_1","type":"invoice.paid","extra":true}', $this->header(), self::SECRET);
    }

    public function testItRejectsAnOldSignatureEvenIfItIsCorrect(): void
    {
        // Una firma válida de hace una hora sigue cuadrando: sin tolerancia,
        // copiarla de un log serviría para siempre.
        $this->expectException(InvalidSignatureException::class);
        $this->expectExceptionMessage('la tolerancia es de 300');

        $this->verifier->verify(self::PAYLOAD, $this->header(timestamp: time() - 3600), self::SECRET);
    }

    public function testItRejectsAFutureTimestampBeyondTheTolerance(): void
    {
        $this->expectException(InvalidSignatureException::class);

        $this->verifier->verify(self::PAYLOAD, $this->header(timestamp: time() + 3600), self::SECRET);
    }

    public function testItAcceptsAnyOfTheSignaturesDuringASecretRotation(): void
    {
        $timestamp = time();

        $this->verifier->verify(
            self::PAYLOAD,
            sprintf(
                't=%d,v1=%s,v1=%s',
                $timestamp,
                $this->sign($timestamp, 'whsec_anterior'),
                $this->sign($timestamp, self::SECRET),
            ),
            self::SECRET,
        );

        $this->expectNotToPerformAssertions();
    }

    public function testItRejectsAHeaderWithoutTimestamp(): void
    {
        $this->expectException(InvalidSignatureException::class);
        $this->expectExceptionMessage('marca de tiempo');

        $this->verifier->verify(self::PAYLOAD, 'v1='.$this->sign(time(), self::SECRET), self::SECRET);
    }

    public function testItRejectsAHeaderWithoutSignatures(): void
    {
        $this->expectException(InvalidSignatureException::class);
        $this->expectExceptionMessage('ninguna firma');

        $this->verifier->verify(self::PAYLOAD, sprintf('t=%d', time()), self::SECRET);
    }

    public function testItRejectsASignatureWithTheWrongLength(): void
    {
        $this->expectException(InvalidSignatureException::class);
        $this->expectExceptionMessage('longitud');

        $this->verifier->verify(self::PAYLOAD, sprintf('t=%d,v1=abc', time()), self::SECRET);
    }

    public function testItRejectsEverythingWhenThereIsNoSecret(): void
    {
        $this->expectException(InvalidSignatureException::class);
        $this->expectExceptionMessage('No hay secreto');

        $this->verifier->verify(self::PAYLOAD, $this->header(), '');
    }

    public function testItRejectsAMissingHeader(): void
    {
        $this->expectException(InvalidSignatureException::class);
        $this->expectExceptionMessage('Falta la cabecera');

        $this->verifier->verify(self::PAYLOAD, null, self::SECRET);
    }

    private function header(?int $timestamp = null, string $secret = self::SECRET): string
    {
        $timestamp ??= time();

        return sprintf('t=%d,v1=%s', $timestamp, $this->sign($timestamp, $secret));
    }

    private function sign(int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.self::PAYLOAD, $secret);
    }
}
