<?php

namespace KoreUi\Calendar\Availability;

use InvalidArgumentException;
use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;

/**
 * Dónde cabe algo: los huecos libres dentro del horario laboral. Puro, pensado
 * para «buscar hueco» y «siguiente disponible»; no pinta nada.
 *
 *     $slots = AvailabilityFinder::freeSlots($instances, $hours, $range, durationSeconds: 3600, resourceId: 'sala-1', snapSeconds: 900);
 *     $next = $slots[0]->start ?? null;   // el primer instante en que cabe una hora
 *
 * Lo que ocupa un hueco es lo mismo que rechazaría `MutationValidator` al soltar
 * encima (`Collision`): cualquier evento que ocupe sitio, y los de fondo que digan
 * `overlap: false`. Un evento de día completo ocupa el día entero.
 *
 * Todo en horas de pared (`Marker`), la zona de la vista.
 */
final class AvailabilityFinder
{
    /**
     * Los huecos libres donde cabe `$durationSeconds`, en orden. Cada uno es el
     * MAYOR tramo libre (no un hueco por posible inicio): empieza en el primer
     * instante válido y `start + duración` siempre cabe dentro. Para ofrecer varias
     * horas basta ir sumando el paso.
     *
     * @param  list<EventInstance>  $instances  Los eventos que pueden ocupar el hueco (cualquiera, el método filtra por recurso).
     * @param  BusinessHours  $hours  Dónde se puede trabajar. Sin horario definido, todo el rango. Para el de un recurso, `BusinessHours::forResource()`.
     * @param  string|int|null  $resourceId  Solo cuentan los eventos de ese recurso. `null` = ningún filtro: cuenta cualquier evento.
     * @param  ?int  $snapSeconds  Los inicios se alinean hacia delante a múltiplos de este paso, contados desde las 00:00
     *                             (con 900, 09:10 pasa a 09:15). `null` = sin alinear.
     * @return list<DateRange>
     */
    public static function freeSlots(
        array $instances,
        BusinessHours $hours,
        DateRange $range,
        int $durationSeconds,
        string|int|null $resourceId = null,
        ?int $snapSeconds = null,
    ): array {
        if ($durationSeconds < 1) {
            throw new InvalidArgumentException("La duración del hueco tiene que ser de al menos 1 segundo (llegó {$durationSeconds}).");
        }

        if ($snapSeconds !== null && $snapSeconds < 1) {
            throw new InvalidArgumentException("El paso del hueco tiene que ser de al menos 1 segundo (llegó {$snapSeconds}).");
        }

        $busy = self::busy($instances, $range, $resourceId);
        $slots = [];

        foreach ($hours->intervals($range) as $open) {
            foreach (self::subtract($open, $busy) as $free) {
                $start = self::align($free->start, $snapSeconds);

                if ($free->end->ts - $start->ts >= $durationSeconds) {
                    $slots[] = new DateRange($start, $free->end);
                }
            }
        }

        return $slots;
    }

    /**
     * Lo ocupado dentro del rango, ordenado y fundido.
     *
     * @param  list<EventInstance>  $instances
     * @return list<array{0: int, 1: int}>
     */
    private static function busy(array $instances, DateRange $range, string|int|null $resourceId): array
    {
        $spans = [];

        foreach ($instances as $instance) {
            $event = $instance->event;

            if (! Collision::occupies($event) && $event->overlap !== false) {
                continue;
            }

            if ($resourceId !== null && ! in_array((string) $resourceId, Collision::lanes($event->resourceIds), true)) {
                continue;
            }

            $part = $instance->range->intersect($range);

            if ($part !== null) {
                $spans[] = [$part->start->ts, $part->end->ts];
            }
        }

        usort($spans, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($spans as [$start, $end]) {
            $last = array_key_last($merged);

            if ($last !== null && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    /**
     * El tramo abierto menos lo ocupado.
     *
     * @param  list<array{0: int, 1: int}>  $busy  Ordenado y disjunto.
     * @return list<DateRange>
     */
    private static function subtract(DateRange $open, array $busy): array
    {
        $free = [];
        $cursor = $open->start->ts;

        foreach ($busy as [$start, $end]) {
            if ($end <= $cursor) {
                continue;
            }

            if ($start >= $open->end->ts) {
                break;
            }

            if ($start > $cursor) {
                $free[] = new DateRange(Marker::fromTimestamp($cursor), Marker::fromTimestamp($start));
            }

            $cursor = max($cursor, $end);
        }

        if ($cursor < $open->end->ts) {
            $free[] = new DateRange(Marker::fromTimestamp($cursor), $open->end);
        }

        return $free;
    }

    /** Hacia delante hasta el siguiente múltiplo del paso (o el mismo instante si ya lo es). */
    private static function align(Marker $start, ?int $snapSeconds): Marker
    {
        if ($snapSeconds === null) {
            return $start;
        }

        $past = $start->secondsOfDay() % $snapSeconds;

        return $past === 0 ? $start : $start->addSeconds($snapSeconds - $past);
    }
}
