<?php

namespace KoreUi\Calendar\Layout;

use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Layout\Kernel\DayRowPlacer;
use KoreUi\Calendar\Layout\Kernel\DayRowPlacement;
use KoreUi\Calendar\Layout\Kernel\Span;

/**
 * Coloca los trozos de cada fila de días con el kernel.
 *
 * Lo usan la vista mes (una fila por semana) y el carril de todo el día de la
 * rejilla horaria (una sola fila). Este archivo no sabe de fechas: recibe
 * `DaySegment` ya cortados y devuelve, por fila, lo que el kernel decidió.
 */
final class DayGridLayout
{
    /**
     * @param  list<DaySegment>  $segments
     * @return array<int, DayRowPlacement> fila => colocación
     */
    public static function place(array $segments, int $rows, int $columns, ?int $maxEvents, ?int $maxEventRows, LayoutOptions $options): array
    {
        $byRow = array_fill(0, $rows, []);

        foreach ($segments as $segment) {
            $byRow[$segment->row][] = $segment;
        }

        $placements = [];

        foreach ($byRow as $row => $rowSegments) {
            // El orden se decide sobre el evento completo (EventOrder), no sobre el trozo.
            usort($rowSegments, fn (DaySegment $a, DaySegment $b) => $options->order->compare($a->instance, $b->instance));

            $spans = array_map(fn (DaySegment $s) => new Span(
                key: $s->instance->instanceId,
                start: $s->colStart,
                end: $s->colEnd,
                payload: $s,
                isStart: $s->isStart,
                isEnd: $s->isEnd,
            ), $rowSegments);

            $placements[$row] = DayRowPlacer::place($spans, $columns, $maxEvents, $maxEventRows, $options->orderStrict, $options->slicing);
        }

        return $placements;
    }

    /**
     * Cómo se dibuja un trozo en una rejilla de días (`hasListItemDisplay`).
     *
     * `auto`: un evento con hora que empieza y termina el mismo día es "punto +
     * hora"; todo lo demás, una barra. Se decide con el trozo completo (inicio y
     * fin dentro del mismo día), no con el primer corte: un evento con hora que
     * cruza la medianoche es barra en los dos días (#5634).
     */
    public static function display(DaySegment $segment): string
    {
        $display = $segment->instance->event->display;

        if ($display !== 'auto') {
            return $display;
        }

        return ! $segment->instance->allDay
            && $segment->colEnd - $segment->colStart === 1
            && $segment->isStart && $segment->isEnd
            ? 'list-item'
            : 'block';
    }

    /** @param list<EventInstance> $instances */
    public static function withoutBackground(array $instances): array
    {
        return array_values(array_filter($instances, fn (EventInstance $i) => ! in_array($i->event->display, ['background', 'none'], true)));
    }
}
