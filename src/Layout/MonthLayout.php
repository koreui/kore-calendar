<?php

namespace KoreUi\Calendar\Layout;

use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\ViewRange;
use KoreUi\Calendar\Time\WeekRules;

/**
 * La vista mes, lista para Blade.
 *
 * `rows[i]` = una semana: sus días (con banderas de hoy, de otro mes, deshabilitado),
 * los trozos visibles con su posición en la rejilla CSS (columna de inicio, de
 * fin, fila) y, por celda, cuántos eventos quedaron ocultos tras el "+N más" y
 * cuáles lista su popover.
 */
final class MonthLayout
{
    /**
     * @param  list<array{days: list<array{date: Marker, isToday: bool, isOther: bool, isDisabled: bool, isPast: bool, hiddenCount: int, popover: list<EventInstance>}>, slices: list<array{instance: EventInstance, key: string, colStart: int, colEnd: int, row: int, isStart: bool, isEnd: bool, display: string, isSlice: bool}>, rowCount: int, weekNumber: ?int}>  $rows
     */
    private function __construct(
        public readonly ViewRange $range,
        public readonly array $rows,
        public readonly int $columns,
    ) {}

    /**
     * @param  list<EventInstance>  $instances
     */
    public static function build(ViewRange $range, array $instances, Marker $now, LayoutOptions $options = new LayoutOptions, ?WeekRules $weekNumbers = null): self
    {
        $visible = $range->days();
        $perRow = self::daysPerRow($visible);
        $weeks = array_chunk($visible, $perRow);

        $slicer = new DaySlicer($weeks, $range->active, $options->nextDayThreshold);
        $segments = [];

        foreach (DayGridLayout::withoutBackground($instances) as $instance) {
            array_push($segments, ...$slicer->slice($instance));
        }

        $placements = DayGridLayout::place($segments, count($weeks), $perRow, $options->maxEvents, $options->maxEventRows, $options);
        $today = $now->startOfDay();
        $rows = [];

        foreach ($weeks as $r => $days) {
            $placement = $placements[$r];
            $slices = [];
            $rowCount = 0;

            foreach ($placement->visible as $placed) {
                /** @var DaySegment $segment */
                $segment = $placed->span->payload;
                $slices[] = [
                    'instance' => $segment->instance,
                    'key' => $placed->key,
                    'colStart' => (int) $placed->start,
                    'colEnd' => (int) $placed->end,
                    'row' => $placed->row,
                    'isStart' => $placed->isStart,
                    'isEnd' => $placed->isEnd,
                    'display' => DayGridLayout::display($segment),
                    'isSlice' => $placed->isSlice,
                ];
                $rowCount = max($rowCount, $placed->row + 1);
            }

            $cells = [];

            foreach ($days as $c => $day) {
                $cell = $placement->cells[$c] ?? null;
                $cells[] = [
                    'date' => $day,
                    'isToday' => $day->equals($today),
                    'isOther' => ! $range->current->contains($day),
                    'isDisabled' => ! $range->active->contains($day),
                    'isPast' => $day->isBefore($today),
                    'hiddenCount' => $cell?->hiddenCount() ?? 0,
                    'popover' => array_map(fn ($span) => $span->payload->instance, $cell?->all ?? []),
                ];
            }

            $rows[] = [
                'days' => $cells,
                'slices' => $slices,
                'rowCount' => $rowCount,
                // El número de semana sale de la primera celda de la fila, y no se
                // muestra con una sola fila (FullCalendar 7.0.0).
                'weekNumber' => $weekNumbers && count($weeks) > 1 ? $weekNumbers->weekNumber($days[0]) : null,
            ];
        }

        return new self($range, $rows, $perRow);
    }

    /** Las columnas de una semana: los días hasta que se repite el día de la semana. */
    private static function daysPerRow(array $days): int
    {
        $seen = [];

        foreach ($days as $i => $day) {
            if (isset($seen[$day->dayOfWeek()])) {
                return $i;
            }
            $seen[$day->dayOfWeek()] = true;
        }

        return max(1, count($days));
    }
}
