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

    /** @return list<TimeSegment> */
    public function slice(EventInstance $instance): array
    {
        if ($instance->allDay) {
            return [];
        }

        $segments = [];

        foreach (array_keys($this->columns) as $column) {
            $range = $this->columnRange($column);
            $part = $instance->range->intersect($range);

            if ($part === null) {
                continue;
            }

            $segments[] = new TimeSegment(
                instance: $instance,
                column: $column,
                start: $part->start->ts - $range->start->ts,
                end: $part->end->ts - $range->start->ts,
                isStart: $part->start->equals($instance->range->start),
                isEnd: $part->end->equals($instance->range->end),
            );
        }

        return $segments;
    }

    public function axisSeconds(): int
    {
        return $this->slotMax - $this->slotMin;
    }
}
