<?php

namespace KoreUi\Calendar\Layout;

use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;

/**
 * Corta eventos en trozos por fila de días: la vista mes y el carril de todo el día.
 *
 * Port de `sliceEventStore` + `DaySeriesModel.sliceRange` + `buildDayGridRanges`
 * de FullCalendar. Todo en índices de columnas VISIBLES: un día oculto no ocupa
 * columna, y un evento que empieza o termina en uno se recorta a la columna
 * visible más cercana y pierde `isStart` / `isEnd`.
 */
final class DaySlicer
{
    /**
     * @param  list<list<Marker>>  $rows  Los días visibles de cada fila, en orden.
     * @param  DateRange  $framing  El rango activo: lo que queda fuera se recorta.
     * @param  int  $nextDayThreshold  Segundos tras la medianoche a partir de los
     *                                 cuales un evento con hora ocupa también el día de su fin.
     */
    public function __construct(
        private readonly array $rows,
        private readonly DateRange $framing,
        private readonly int $nextDayThreshold = 0,
    ) {}

    /** @return list<DaySegment> */
    public function slice(EventInstance $instance): array
    {
        $days = $instance->allDay ? $instance->range : self::visibleDayRange($instance->range, $this->nextDayThreshold);
        $clipped = $days->intersect($this->framing);

        if ($clipped === null) {
            return [];
        }

        $startsHere = $clipped->start->equals($days->start);
        $endsHere = $clipped->end->equals($days->end);
        $segments = [];

        foreach ($this->rows as $rowIndex => $row) {
            $cols = [];

            foreach ($row as $col => $day) {
                if ($clipped->contains($day)) {
                    $cols[] = $col;
                }
            }

            if ($cols === []) {
                continue;
            }

            $colStart = $cols[0];
            $colEnd = $cols[count($cols) - 1] + 1;

            $segments[] = new DaySegment(
                instance: $instance,
                row: $rowIndex,
                colStart: $colStart,
                colEnd: $colEnd,
                // Solo es inicio de verdad si el día de inicio está en esta fila y visible.
                isStart: $startsHere && $row[$colStart]->equals($clipped->start),
                isEnd: $endsHere && $row[$colEnd - 1]->addDays(1)->equals($clipped->end),
            );
        }

        return $segments;
    }

    /**
     * Los días que ocupa un evento con hora (`computeVisibleDayRange` de FullCalendar).
     *
     * Terminar exactamente a medianoche no ocupa el día siguiente; terminar un
     * segundo después, sí (con umbral 0). Con umbral 09:00, terminar a las 09:00
     * cuenta y a las 08:59 no. Siempre al menos un día.
     */
    public static function visibleDayRange(DateRange $range, int $threshold): DateRange
    {
        $startDay = $range->start->startOfDay();
        $endDay = $range->end->startOfDay();
        $intoEndDay = $range->end->ts - $endDay->ts;

        if ($intoEndDay !== 0 && $intoEndDay >= $threshold) {
            $endDay = $endDay->addDays(1);
        }

        if ($endDay->ts <= $startDay->ts) {
            $endDay = $startDay->addDays(1);
        }

        return new DateRange($startDay, $endDay);
    }
}
