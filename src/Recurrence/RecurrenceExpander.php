<?php

namespace KoreUi\Calendar\Recurrence;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;
use RRule\RRule;

/**
 * Expande una serie (`rrule`) en sus ocurrencias dentro de un rango.
 *
 * **En qué zona se repite.** Es la decisión que más bugs dio en FullCalendar
 * (#5993: rrule en UTC con una zona de vista distinta perdía ocurrencias; #5726:
 * un EXDATE con `Z` no excluía):
 *
 * - `start` sin zona ('2026-10-12 09:00') o de día completo → regla **flotante**,
 *   en horas de pared de la vista. «Cada lunes a las 9» sigue a las 9 tras el
 *   cambio de hora, porque se calcula en un espacio donde no existe.
 * - `start` con zona (una fecha de Eloquent, un ISO con offset) → la regla corre
 *   **en esa zona** y cada ocurrencia se convierte a la vista. «Las 9 de Madrid»
 *   vistas desde Nueva York caen a las 3 o a las 4 según la semana: los dos
 *   países cambian de hora en fechas distintas, y es lo correcto.
 *
 * Las exclusiones (`exdates`) y los `recurrenceId` de las ocurrencias modificadas
 * se comparan en esa misma representación, con `key()`, nunca como texto. Una
 * fecha sin hora excluye la ocurrencia de ese día, sea a la hora que sea.
 */
final class RecurrenceExpander
{
    public function __construct(
        private readonly WallClock $clock,
        private readonly EventNormalizer $normalizer,
        private readonly int $maxOccurrences = 1000,
    ) {}

    /**
     * @param  array<string, true>  $skip  Claves (`key()`) de ocurrencias que sustituye una modificada.
     * @return list<EventInstance>
     */
    public function expand(CalendarEvent $series, DateRange $range, array $skip = []): array
    {
        $first = $this->normalizer->normalize($series->occurrence($series->start, $series->end));
        $zone = self::zoneOf($series, $first->allDay);

        $markerDuration = $first->range->seconds();
        $realDuration = $first->exactStart && $first->exactEnd
            ? $first->exactEnd->getTimestamp() - $first->exactStart->getTimestamp()
            : $markerDuration;

        // Hacia atrás por la duración: una ocurrencia que empezó antes de la vista
        // y entra en ella también cuenta (FullCalendar 4.2.0, #4617). Un día de
        // margen a cada lado por la diferencia de zonas; lo que sobre lo filtra el colector.
        $dtstart = self::dtstart($first, $zone);

        if ($zone === null) {
            $from = self::utc($range->start->ts - $markerDuration - Marker::DAY);
            $to = self::utc($range->end->ts + Marker::DAY);
        } else {
            $from = $this->clock->toInstant($range->start)->subSeconds($realDuration)->subDay()->setTimezone($zone);
            $to = $this->clock->toInstant($range->end)->addDay()->setTimezone($zone);
        }

        try {
            $rule = new RRule(self::normalizeUntil($series->rrule, $zone), $dtstart);
        } catch (InvalidArgumentException $e) {
            // Solo los errores de la regla: un Error (clase que falta, bug) sale
            // tal cual, o se presentaría como «regla no válida» y despistaría.
            throw new InvalidArgumentException("Evento «{$series->id}»: `rrule` no válida ({$e->getMessage()}).", 0, $e);
        }

        $occurrences = $rule->getOccurrencesBetween($from, $to, $this->maxOccurrences + 1);

        if (count($occurrences) > $this->maxOccurrences) {
            // Una regla FREQ=MINUTELY no puede tumbar la página. Se avisa una vez y se corta.
            if (function_exists('logger')) {
                logger()->warning("kore-calendar: la serie «{$series->id}» tiene más de {$this->maxOccurrences} ocurrencias en la vista; se muestran las primeras.");
            }
            $occurrences = array_slice($occurrences, 0, $this->maxOccurrences);
        }

        $excluded = [];
        foreach ($series->exdates as $exdate) {
            $excluded[self::key($series, $exdate, $this->clock)] = true;
        }

        $instances = [];

        foreach ($occurrences as $occurrence) {
            $exact = self::occurrenceKey($zone, $occurrence);
            $day = 'd:'.$occurrence->format('Y-m-d');

            // La modificada se identifica igual que un EXDATE: por la hora exacta o, si su
            // `recurrenceId` es una fecha sin hora (lo natural en un día completo), por el día.
            if (isset($excluded[$exact]) || isset($excluded[$day]) || isset($skip[$exact]) || isset($skip[$day])) {
                continue;
            }

            if ($zone === null) {
                $marker = Marker::fromTimestamp((int) $occurrence->format('U'));
                $start = $first->allDay ? $marker->toDateString() : $marker->toDateTimeString();
                $end = $first->hasEnd
                    ? ($first->allDay ? $marker->addSeconds($markerDuration)->toDateString() : $marker->addSeconds($markerDuration)->toDateTimeString())
                    : null;
                $original = $marker;
                $instant = $this->clock->toInstant($marker);
            } else {
                $instant = CarbonImmutable::instance($occurrence);
                $start = $instant;
                $end = $first->hasEnd ? $instant->addSeconds($realDuration) : null;
                $original = $this->clock->toMarker($instant);
            }

            $normalized = $this->normalizer->normalize($series->occurrence($start, $end));

            $instances[] = new EventInstance(
                event: $normalized->event,
                range: $normalized->range,
                allDay: $normalized->allDay,
                hasEnd: $normalized->hasEnd,
                exactStart: $normalized->exactStart,
                exactEnd: $normalized->exactEnd,
                // Estable aunque la ocurrencia se mueva: identifica cuál es, no dónde está.
                instanceId: $series->id.':'.$original->ts,
                seriesId: $series->id,
                recurrenceId: $original,
                occurrenceStart: $instant->setTimezone($this->clock->zone),
            );
        }

        return $instances;
    }

    /**
     * La clave de una fecha (exdate, recurrenceId) en la representación de la
     * serie: `w:` hora de pared (flotante), `i:` instante (con zona), `d:` día entero.
     */
    public static function key(CalendarEvent $series, DateTimeInterface|string $value, WallClock $clock): string
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            return 'd:'.trim($value);
        }

        $zone = self::zoneOf($series, false);

        if ($zone === null) {
            // Flotante: una fecha con zona se lleva a la hora de pared de la vista.
            return 'w:'.$clock->toMarker($value)->ts;
        }

        $instant = is_string($value) && ! preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', trim($value))
            ? new DateTimeImmutable($value, $zone)
            : ($value instanceof DateTimeInterface ? $value : new DateTimeImmutable($value));

        return 'i:'.$instant->getTimestamp();
    }

    /**
     * RFC 5545 exige `UNTIL` en UTC cuando el inicio lleva hora y zona, y
     * php-rrule lo hace cumplir. Pero lo natural es escribir `UNTIL=20261021`
     * («hasta ese día»): rechazarlo sería hostil. Se normaliza en la
     * representación de la serie: una fecha sin hora es hasta el final de ese día
     * (inclusive), y una hora sin `Z` es de pared (flotante) o de la zona de la serie.
     */
    public static function normalizeUntil(string $rrule, ?DateTimeZone $zone): string
    {
        return preg_replace_callback('/UNTIL=(\d{8})(?:T(\d{6}))?(Z?)/i', function (array $m) use ($zone) {
            if ($m[3] !== '') {
                return $m[0];
            }

            $wall = DateTimeImmutable::createFromFormat('YmdHis', $m[1].($m[2] !== '' ? $m[2] : '235959'), $zone ?? new DateTimeZone('UTC'));

            return 'UNTIL='.$wall->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
        }, $rrule);
    }

    /**
     * El `DTSTART` que se le da a php-rrule, a partir de la primera ocurrencia
     * normalizada: la hora de pared como si fuera UTC (flotante o día completo)
     * o el instante real en la zona de la serie. Público porque `RecurrenceEditor`
     * cuenta ocurrencias con la MISMA regla que la expansión: si calcularan el
     * inicio por separado, un COUNT cortado en «esta y las siguientes» podría
     * no coincidir con lo que se dibuja.
     */
    public static function dtstart(EventInstance $first, ?DateTimeZone $zone): DateTimeImmutable
    {
        if ($zone === null) {
            return self::utc($first->range->start->ts);
        }

        return (new DateTimeImmutable('@'.$first->exactStart->getTimestamp()))->setTimezone($zone);
    }

    /** null = flotante (horas de pared de la vista); si no, la zona de la serie. */
    public static function zoneOf(CalendarEvent $series, bool $allDay): ?DateTimeZone
    {
        if ($allDay || $series->allDay === true) {
            return null;
        }

        if ($series->start instanceof DateTimeInterface) {
            return $series->start->getTimezone();
        }

        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', trim($series->start))) {
            return (new DateTimeImmutable($series->start))->getTimezone();
        }

        return null;
    }

    private static function occurrenceKey(?DateTimeZone $zone, DateTimeInterface $occurrence): string
    {
        return $zone === null ? 'w:'.$occurrence->format('U') : 'i:'.$occurrence->getTimestamp();
    }

    private static function utc(int $ts): DateTimeImmutable
    {
        return new DateTimeImmutable('@'.$ts);
    }
}
