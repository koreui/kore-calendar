<?php

namespace KoreUi\Calendar\Layout;

use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Layout\Kernel\Span;
use KoreUi\Calendar\Layout\Kernel\TimeColumnPlacer;
use KoreUi\Calendar\Resources\CalendarResource;
use KoreUi\Calendar\Resources\ResourceSet;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\ViewRange;

/**
 * Semana, día y sus variantes por recurso, listas para Blade.
 *
 * Una columna es un día, o un par (día, recurso). Para el motor un recurso es
 * "una columna más" (el `Splitter` de FullCalendar): el kernel corre columna a
 * columna sin saber de recursos.
 *
 * Arriba, el carril de todo el día: una rejilla de días de una sola fila. Abajo,
 * cada columna con sus eventos en porcentajes del eje (`top`, `height`, `left`,
 * `right`), así que el navegador escala sin JavaScript.
 *
 * Los eventos sin recurso no aparecen en las vistas por recurso, como en
 * FullCalendar: no hay columna a la que pertenezcan.
 */
final class TimeGridLayout
{
    /**
     * @param  list<array{date: Marker, resource: ?CalendarResource, isToday: bool, isPast: bool}>  $columns
     * @param  array{slices: list<array>, cells: list<array{hiddenCount: int, popover: list<EventInstance>}>, rowCount: int}  $allDay
     * @param  list<array{events: list<array>, hidden: list<array>}>  $lanes
     * @param  list<array{start: int, label: Marker}>  $slots
     */
    private function __construct(
        public readonly ViewRange $range,
        public readonly array $columns,
        public readonly array $allDay,
        public readonly array $lanes,
        public readonly array $slots,
        public readonly int $axisSeconds,
        public readonly ?float $nowTop,
        public readonly ?int $nowColumn,
    ) {}

    /**
     * @param  list<EventInstance>  $instances
     * @param  bool  $datesAboveResources  Cabecera fecha → recursos (true) o recurso → fechas (false).
     */
    public static function build(
        ViewRange $range,
        array $instances,
        Marker $now,
        LayoutOptions $options = new LayoutOptions,
        ?ResourceSet $resources = null,
        bool $datesAboveResources = false,
        int $slotDuration = 1800,
    ): self {
        $today = $now->startOfDay();
        $days = $range->days();
        $columns = self::columns($days, $resources, $datesAboveResources, $today);
        $instances = DayGridLayout::withoutBackground($instances);
        $axis = $options->slotMax - $options->slotMin;
        ['nowTop' => $nowTop, 'nowColumn' => $nowColumn] = self::now($columns, $now, $options, $axis);

        return new self(
            range: $range,
            columns: $columns,
            allDay: self::allDayLane($columns, $instances, $range, $options),
            lanes: self::lanes($columns, $instances, $options, $axis),
            slots: self::slots($options, $slotDuration),
            axisSeconds: $axis,
            nowTop: $nowTop,
            nowColumn: $nowColumn,
        );
    }

    /** @return list<array{date: Marker, resource: ?CalendarResource, isToday: bool, isPast: bool}> */
    private static function columns(array $days, ?ResourceSet $resources, bool $datesAbove, Marker $today): array
    {
        $col = fn (Marker $d, ?CalendarResource $r) => ['date' => $d, 'resource' => $r, 'isToday' => $d->equals($today), 'isPast' => $d->isBefore($today)];

        if ($resources === null || $resources->isEmpty()) {
            return array_map(fn ($d) => $col($d, null), $days);
        }

        $list = array_map(fn ($entry) => $entry['resource'], $resources->flatten());
        $columns = [];

        if ($datesAbove) {
            foreach ($days as $d) {
                foreach ($list as $r) {
                    $columns[] = $col($d, $r);
                }
            }
        } else {
            foreach ($list as $r) {
                foreach ($days as $d) {
                    $columns[] = $col($d, $r);
                }
            }
        }

        return $columns;
    }

    private static function belongs(EventInstance $i, ?CalendarResource $resource): bool
    {
        if ($resource === null) {
            return true;
        }

        foreach ($i->event->resourceIds as $id) {
            if ((string) $id === (string) $resource->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * El carril de todo el día. Con recursos, cada recurso corta sus eventos sobre
     * SUS columnas; si esas columnas no son contiguas (fecha sobre recurso), el
     * trozo se parte en uno por columna, porque una barra no puede saltar huecos.
     */
    private static function allDayLane(array $columns, array $instances, ViewRange $range, LayoutOptions $options): array
    {
        $groups = [];

        foreach ($columns as $index => $c) {
            $groups[$c['resource'] ? 'r:'.$c['resource']->id : '-'][] = $index;
        }

        $segments = [];

        foreach ($groups as $indexes) {
            $resource = $columns[$indexes[0]]['resource'];
            $slicer = new DaySlicer([array_map(fn ($i) => $columns[$i]['date'], $indexes)], $range->active, $options->nextDayThreshold);

            foreach ($instances as $instance) {
                if (! $instance->allDay || ! self::belongs($instance, $resource)) {
                    continue;
                }

                foreach ($slicer->slice($instance) as $s) {
                    $global = array_slice($indexes, $s->colStart, $s->colEnd - $s->colStart);
                    $contiguous = end($global) - $global[0] === count($global) - 1;

                    if ($contiguous) {
                        $segments[] = new DaySegment($instance, 0, $global[0], end($global) + 1, $s->isStart, $s->isEnd);

                        continue;
                    }

                    foreach ($global as $k => $g) {
                        $segments[] = new DaySegment($instance, 0, $g, $g + 1, $s->isStart && $k === 0, $s->isEnd && $k === count($global) - 1);
                    }
                }
            }
        }

        $placement = DayGridLayout::place($segments, 1, count($columns), null, $options->allDayMaxEventRows, $options)[0];
        $slices = [];
        $rowCount = 0;

        foreach ($placement->visible as $placed) {
            $slices[] = [
                'instance' => $placed->span->payload->instance,
                'key' => $placed->key,
                'colStart' => (int) $placed->start,
                'colEnd' => (int) $placed->end,
                'row' => $placed->row,
                'isStart' => $placed->isStart,
                'isEnd' => $placed->isEnd,
            ];
            $rowCount = max($rowCount, $placed->row + 1);
        }

        $cells = [];
        foreach (array_keys($columns) as $c) {
            $cell = $placement->cells[$c] ?? null;
            $cells[] = [
                'hiddenCount' => $cell?->hiddenCount() ?? 0,
                'popover' => array_map(fn ($span) => $span->payload->instance, $cell?->all ?? []),
            ];
        }

        return ['slices' => $slices, 'cells' => $cells, 'rowCount' => $rowCount];
    }

    private static function lanes(array $columns, array $instances, LayoutOptions $options, int $axis): array
    {
        $slicer = new TimeSlicer(array_map(fn ($c) => $c['date'], $columns), $options->slotMin, $options->slotMax);
        $byColumn = array_fill(0, count($columns), []);

        foreach ($instances as $instance) {
            if ($instance->allDay) {
                continue;
            }

            foreach ($slicer->slice($instance) as $segment) {
                if (self::belongs($instance, $columns[$segment->column]['resource'])) {
                    $byColumn[$segment->column][] = $segment;
                }
            }
        }

        $pct = fn (float $seconds) => round($seconds / max(1, $axis) * 100, 4);
        $lanes = [];

        foreach ($byColumn as $segments) {
            usort($segments, fn (TimeSegment $a, TimeSegment $b) => $options->order->compare($a->instance, $b->instance));

            // La altura mínima se aplica ANTES de colisionar: dos eventos de un
            // minuto separados por 80 s no se tocan en el tiempo, pero sí en
            // pantalla, y tienen que ir lado a lado (#961, eventMinHeight.ts).
            $spans = array_map(fn (TimeSegment $s) => new Span(
                key: $s->instance->instanceId,
                start: $s->start,
                end: max($s->end, min($axis, $s->start + $options->eventMinSeconds)),
                payload: $s,
                isStart: $s->isStart,
                isEnd: $s->isEnd,
            ), $segments);

            $placement = TimeColumnPlacer::place($spans, $options->orderStrict, $options->maxStack, $options->slotEventOverlap);

            $lanes[] = [
                'events' => array_map(fn ($p) => [
                    'instance' => $p->span->payload->instance,
                    'segment' => $p->span->payload,
                    'key' => $p->span->key.':'.$p->span->payload->column,
                    'top' => $pct($p->span->start),
                    'height' => $pct($p->span->end - $p->span->start),
                    'left' => round($p->left * 100, 4),
                    'right' => round($p->right * 100, 4),
                    'zIndex' => $p->zIndex,
                    'marginEnd' => $p->marginEnd,
                    'isShort' => $p->span->end - $p->span->start <= $options->eventShortSeconds,
                ], $placement->placed),
                'hidden' => array_map(fn ($g) => [
                    'key' => $g->key,
                    'top' => $pct($g->start),
                    'height' => $pct($g->end - $g->start),
                    'instances' => array_map(fn ($span) => $span->payload->instance, $g->spans),
                ], $placement->hidden),
            ];
        }

        return $lanes;
    }

    /** @return list<array{start: int, label: Marker}> Cada slot, en segundos desde el inicio del eje. */
    private static function slots(LayoutOptions $options, int $slotDuration): array
    {
        $slots = [];

        // Etiquetas relativas al inicio del eje, no a la hora de reloj: con
        // slotMin 00:20 se etiqueta 00:20, 01:20… (FullCalendar #2786).
        for ($t = 0; $t < $options->slotMax - $options->slotMin; $t += $slotDuration) {
            $slots[] = ['start' => $t, 'label' => Marker::fromTimestamp($options->slotMin + $t)];
        }

        return $slots;
    }

    /** @return array{nowTop: ?float, nowColumn: ?int} */
    private static function now(array $columns, Marker $now, LayoutOptions $options, int $axis): array
    {
        // Con slotMax > 24 h "ahora" puede estar en la columna del día anterior
        // (FullCalendar 7.1.3, #6394): se busca en todas.
        foreach ($columns as $i => $c) {
            $start = $c['date']->addSeconds($options->slotMin);
            $offset = $now->ts - $start->ts;

            if ($offset >= 0 && $offset < $axis) {
                return ['nowTop' => round($offset / $axis * 100, 4), 'nowColumn' => $i];
            }
        }

        return ['nowTop' => null, 'nowColumn' => null];
    }
}
