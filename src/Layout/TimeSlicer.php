<?php

namespace KoreUi\Calendar\Layout;

use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;

/**
 * Corta eventos con hora en un trozo por columna de la rejilla horaria.
 *
 * Cada columna cubre `[día + slotMin, día + slotMax)`. Con `slotMax > 24 h` las
 * columnas consecutivas se solapan y un evento de madrugada se pinta en dos
 * (FullCalendar, `event-render/maxTime.ts`); con `slotMin < 0`, igual hacia el
 * día anterior. Un evento con hora de varios días nunca va al carril de todo el
 * día: aquí se parte en un trozo por columna.
 */
final class TimeSlicer
{
    /**
     * @param  list<Marker>  $columns  El día de cada columna.
     */
    public function __construct(
        private readonly array $columns,
        private readonly int $slotMin = 0,
        private readonly int $slotMax = Marker::DAY,
    ) {}

    /** El rango de horas de pared que cubre una columna. */
    public function columnRange(int $column): DateRange
    {
        $day = $this->columns[$column];

        return new DateRange($day->addSeconds($this->slotMin), $day->addSeconds($this->slotMax));
    }

    /**
     * @param  ?list<int>  $only  Solo estas columnas (sus índices). Por defecto, todas: quien ya sabe a qué columnas
     *                            puede ir el evento (las de sus recursos) no paga por cortarlo contra el resto.
     * @return list<TimeSegment>
     */
    public function slice(EventInstance $instance, ?array $only = null): array
    {
        if ($instance->allDay) {
            return [];
        }

        $segments = [];
        $from = $instance->range->start->ts;
        $to = $instance->range->end->ts;

        // Aritmética entera sobre el eje de cada columna: sin un DateRange ni un Marker por (evento, columna).
        foreach ($only ?? array_keys($this->columns) as $column) {
            $day = $this->columns[$column]->ts;
            $origin = $day + $this->slotMin;
            $limit = $day + $this->slotMax;
            $start = $from > $origin ? $from : $origin;
            $end = $to < $limit ? $to : $limit;

            if ($start >= $end) {
                continue;
            }

            $segments[] = new TimeSegment(
                instance: $instance,
                column: $column,
                start: $start - $origin,
                end: $end - $origin,
                isStart: $start === $from,
                isEnd: $end === $to,
            );
        }

        return $segments;
    }

    public function axisSeconds(): int
    {
        return $this->slotMax - $this->slotMin;
    }
}
