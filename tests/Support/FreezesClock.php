<?php

declare(strict_types=1);

namespace App\Tests\Support;

use DateInterval;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Clock\MockClock;

/**
 * Congela el reloj del sistema para un test.
 *
 * El reloj es el reloj real del sistema, y un test que depende de "ahora" es un
 * test que empieza a fallar solo: cuando pasa el tiempo, la ventana de 30 días
 * que el test daba por cierta ya no lo es, y el fallo no dice nada del código
 * que se quiere probar.
 *
 * Con `MockClock` el tiempo queda donde el test quiere, y el fallo que aparezca
 * es siempre un fallo real.
 *
 * El reloj se sustituye por el alias publico `test.clock` (vease
 * `config/services_test.yaml`). El servicio `clock` del framework es privado, y
 * un servicio privado que consume una sola vez acabaria *inlineado*: el test
 * obtendra una instancia distinta de la que recibe la aplicacion, y la
 * sustitucion no tendria ningun efecto.
 */
trait FreezesClock
{
    private ?MockClock $frozenClock = null;

    /**
     * Congela el reloj en el instante dado.
     *
     * @param string $now fecha en cualquier formato que entienda `DateTimeImmutable`
     */
    protected function freezeClock(string $now = '2026-10-20 09:00:00'): DateTimeImmutable
    {
        $instant = new DateTimeImmutable($now);

        $this->frozenClock = new MockClock($instant);
        self::getContainer()->set('test.clock', $this->frozenClock);

        return $instant;
    }

    /**
     * Avanza el reloj congelado.
     *
     * @param string $modifier Un modificador de `DateTimeImmutable` (`+1 day`, `+2 weeks`...).
     */
    protected function advanceClock(string $modifier): void
    {
        if (null === $this->frozenClock) {
            throw new LogicException('El reloj no está congelado: llama antes a freezeClock().');
        }

        // `MockClock::sleep()` acepta segundos, no intervalos: se traduce el
        // modificador a segundos reales para que el salto sea exacto.
        $this->frozenClock->sleep($this->intervalToSeconds($modifier));
    }

    private function intervalToSeconds(string $modifier): float
    {
        $reference = new DateTimeImmutable('@0');
        $end = $reference->add(new DateInterval($modifier));

        return (float) $end->getTimestamp() - (float) $reference->getTimestamp();
    }
}
