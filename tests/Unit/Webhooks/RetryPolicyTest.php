<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhooks;

use App\Webhooks\Domain\Service\RetryPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * La espera entre reintentos (Fase 20).
 *
 * La propiedad que importa es que la espera **crezca**: un receptor caído al
 * que se martillea cada treinta segundos durante una semana es un ataque, no
 * una integración.
 */
final class RetryPolicyTest extends TestCase
{
    public function testTheWaitGrowsWithEachAttempt(): void
    {
        $policy = new RetryPolicy();
        $failedAt = new DateTimeImmutable('2026-10-28 12:00:00');

        $previous = 0;

        for ($attempts = 1; $attempts <= $policy->maxRetries(); ++$attempts) {
            $next = $policy->nextAttemptAt($attempts, $failedAt);
            self::assertNotNull($next);

            $seconds = $next->getTimestamp() - $failedAt->getTimestamp();

            self::assertGreaterThanOrEqual($previous, $seconds, 'La espera nunca puede encogerse.');
            $previous = $seconds;
        }
    }

    public function testTheFirstRetryIsSoonSoATransientGlitchCostsNothing(): void
    {
        $policy = new RetryPolicy();

        $next = $policy->nextAttemptAt(1, new DateTimeImmutable('2026-10-28 12:00:00'));

        self::assertNotNull($next);
        self::assertSame('2026-10-28 12:00:30', $next->format('Y-m-d H:i:s'));
    }

    public function testThereIsNoNextAttemptOnceTheAttemptsAreExhausted(): void
    {
        $policy = new RetryPolicy();
        $failedAt = new DateTimeImmutable('2026-10-28 12:00:00');

        self::assertNull($policy->nextAttemptAt($policy->maxRetries() + 1, $failedAt));
        self::assertNull($policy->nextAttemptAt(0, $failedAt));
    }

    public function testTheWholeSequenceFitsInsideTheAbandonWindow(): void
    {
        $policy = new RetryPolicy();
        $start = new DateTimeImmutable('2026-10-28 12:00:00');

        $total = 0;

        for ($attempts = 1; $attempts <= $policy->maxRetries(); ++$attempts) {
            $next = $policy->nextAttemptAt($attempts, $start);
            self::assertNotNull($next);
            $total += $next->getTimestamp() - $start->getTimestamp();
        }

        self::assertLessThan(
            $policy->abandonAfterDays() * 86_400,
            $total,
            'Si la suma de esperas pasara del plazo de abandono, los últimos reintentos nunca llegarían a intentarse.',
        );
    }
}
