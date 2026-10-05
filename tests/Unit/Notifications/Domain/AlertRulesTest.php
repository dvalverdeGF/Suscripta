<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Domain;

use App\Notifications\Domain\Enum\AlertSeverity;
use App\Notifications\Domain\Enum\AlertType;
use App\Notifications\Domain\Enum\NotificationChannel;
use App\Notifications\Domain\Service\AlertRules;

use function array_map;
use function count;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sort;
use function sprintf;

/**
 * Los umbrales de aviso son decisiones de producto, así que se prueban como
 * tales: qué se considera urgente, cuándo una subida merece un aviso y qué
 * canales están activos por defecto.
 */
final class AlertRulesTest extends TestCase
{
    public function testNoticeLeadIsTwiceTheNoticePeriod(): void
    {
        self::assertSame(60, AlertRules::noticeLeadDays(30));
        self::assertSame(14, AlertRules::noticeLeadDays(7));
    }

    public function testNoticeLeadNeverGoesBelowTheMinimum(): void
    {
        // Un contrato sin preaviso no puede traducirse en «avísame el mismo día».
        self::assertSame(AlertRules::NOTICE_LEAD_MIN_DAYS, AlertRules::noticeLeadDays(0));
        self::assertSame(AlertRules::NOTICE_LEAD_MIN_DAYS, AlertRules::noticeLeadDays(2));
    }

    #[DataProvider('chargeSeverityProvider')]
    public function testChargeSeverity(int $days, AlertSeverity $expected): void
    {
        self::assertSame($expected, AlertRules::chargeSeverity($days));
    }

    /**
     * @return iterable<string, array{int, AlertSeverity}>
     */
    public static function chargeSeverityProvider(): iterable
    {
        yield 'hoy' => [0, AlertSeverity::CRITICAL];
        yield 'en el límite' => [AlertRules::CRITICAL_CHARGE_DAYS, AlertSeverity::CRITICAL];
        yield 'justo después del límite' => [AlertRules::CRITICAL_CHARGE_DAYS + 1, AlertSeverity::WARNING];
        yield 'al final de la ventana' => [AlertRules::CHARGE_LEAD_DAYS, AlertSeverity::WARNING];
    }

    #[DataProvider('renewalSeverityProvider')]
    public function testRenewalSeverity(int $days, AlertSeverity $expected): void
    {
        self::assertSame($expected, AlertRules::renewalSeverity($days));
    }

    /**
     * @return iterable<string, array{int, AlertSeverity}>
     */
    public static function renewalSeverityProvider(): iterable
    {
        yield 'hoy' => [0, AlertSeverity::CRITICAL];
        yield 'en el límite' => [AlertRules::CRITICAL_RENEWAL_DAYS, AlertSeverity::CRITICAL];
        yield 'justo después del límite' => [AlertRules::CRITICAL_RENEWAL_DAYS + 1, AlertSeverity::WARNING];
    }

    public function testOnlyCriticalWarrantsEmail(): void
    {
        self::assertTrue(AlertSeverity::CRITICAL->warrantsEmail());
        self::assertFalse(AlertSeverity::WARNING->warrantsEmail());
        self::assertFalse(AlertSeverity::INFO->warrantsEmail());
    }

    #[DataProvider('increaseProvider')]
    public function testIsNoteworthyIncrease(?float $ratio, bool $expected): void
    {
        self::assertSame($expected, AlertRules::isNoteworthyIncrease($ratio));
    }

    /**
     * @return iterable<string, array{?float, bool}>
     */
    public static function increaseProvider(): iterable
    {
        yield 'sin historial' => [null, false];
        yield 'sin cambio' => [0.0, false];
        yield 'por debajo del umbral' => [0.04, false];
        yield 'justo en el umbral' => [AlertRules::PRICE_INCREASE_MIN_RATIO, true];
        yield 'muy por encima' => [0.5, true];
    }

    public function testInAppIsEnabledByDefaultForEveryType(): void
    {
        foreach (AlertType::cases() as $type) {
            self::assertTrue(
                AlertRules::defaultEnabled($type, NotificationChannel::IN_APP),
                sprintf('El tipo %s debería estar activo en la bandeja.', $type->value),
            );
        }
    }

    public function testEmailIsDisabledByDefaultForEveryType(): void
    {
        foreach (AlertType::cases() as $type) {
            self::assertFalse(
                AlertRules::defaultEnabled($type, NotificationChannel::EMAIL),
                sprintf('El tipo %s no debería enviar correo por defecto.', $type->value),
            );
        }
    }

    public function testGeneratedTypesCoverEveryType(): void
    {
        $generated = AlertRules::generatedTypes();

        self::assertCount(count(AlertType::cases()), $generated);

        $values = array_map(static fn (AlertType $type): string => $type->value, $generated);
        $expected = array_map(static fn (AlertType $type): string => $type->value, AlertType::cases());

        sort($expected);
        sort($values);

        self::assertSame($expected, $values);
    }

    public function testOnlyUpcomingChargeIsNotSilenceable(): void
    {
        foreach (AlertType::cases() as $type) {
            self::assertSame(
                AlertType::UPCOMING_CHARGE !== $type,
                $type->isSilenceable(),
                sprintf('Tipo %s: silenciable inesperado.', $type->value),
            );
        }
    }

    public function testAnticipatedTypesAreTheDateDrivenOnes(): void
    {
        self::assertTrue(AlertType::UPCOMING_CHARGE->isAnticipated());
        self::assertTrue(AlertType::UPCOMING_RENEWAL->isAnticipated());
        self::assertTrue(AlertType::ANNUAL_RENEWAL->isAnticipated());
        self::assertTrue(AlertType::NOTICE_DEADLINE->isAnticipated());
        self::assertTrue(AlertType::COMMITMENT_ENDING->isAnticipated());
        self::assertFalse(AlertType::PRICE_INCREASE->isAnticipated());
        self::assertFalse(AlertType::DISCOVERY_PENDING->isAnticipated());
    }
}
