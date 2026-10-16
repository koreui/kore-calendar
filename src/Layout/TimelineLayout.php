<?php

namespace KoreUi\Calendar\Layout;

use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Layout\Kernel\PlacementKernel;
use KoreUi\Calendar\Layout\Kernel\Source;
use KoreUi\Calendar\Resources\CalendarResource;
use KoreUi\Calendar\Resources\ResourceSet;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\ViewRange;

/**
 * El timeline: el tiempo en horizontal y cada recurso en una fila.
 *
 * Mismo kernel que el resto, con el eje lateral horizontal (FullCalendar usa
 * `seg-placement/kernel.ts` también para su Timeline Premium): dentro de una fila,
 * cada evento cae en la primera subfila donde no choca, y el alto de la fila es
 * su número de subfilas. Sin límite: en un timeline nada se esconde tras un
 * "+N más", la fila crece.
 *
 * El eje es de horas de pared, como la rejilla horaria: el día del cambio de
 * horario dibuja sus 24 ranuras aunque dure 23 o 25 h. Es la misma decisión en
 * todas las vistas, y la que hace que una fila de días cuadre con la cabecera.
 *
 * Virtualización: con muchos recursos solo se montan las filas de una ventana
 * (`rowStart`, `rowLimit`) y el resto se sustituye por espaciadores. Las filas son
 * de alto variable, así que el espaciador es una estimación (`estimatedRowCount`
 * filas de una subfila); el scroll se corrige al montar la ventana real.
 */
final class TimelineLayout
{
    /**
     * @param  list<array{start: int, label: Marker, isMajor: bool}>  $slots
     * @param  list<array{type: 'group', key: string, label: string, count: int}|array{type: 'resource', key: string, resource: ?CalendarResource, depth: int, group: ?string, events: list<array>, levels: int}>  $rows
     */
    private function __construct(
        public readonly ViewRange $range,
        public readonly array $slots,
        public readonly int $slotSeconds,
        public readonly int $axisSeconds,
        public readonly array $rows,
        public readonly int $totalRows,
        public readonly int $rowsBefore,
        public readonly int $rowsAfter,
        public readonly ?float $nowLeft,
    ) {}

    /**
     * @param  list<EventInstance>  $instances
     */
    public static function build(
        ViewRange $range,
        array $instances,
        Marker $now,
        LayoutOptions $options = new LayoutOptions,
        ?ResourceSet $resources = null,
        int $slotSeconds = 3600,
        ?int $rowStart = null,
        ?int $rowLimit = null,
    ): self {
        $axis = new DateRange($range->render->start, $range->render->end);
        $axisSeconds = $axis->seconds();
        $instances = DayGridLayout::withoutBackground($instances);

        $all = self::rowSkeleton($resources);
        $total = count($all);
        $start = max(0, min($rowStart ?? 0, max(0, $total - 1)));
        $limit = $rowLimit ?? $total;
        $window = array_slice($all, $start, $limit);

        $rows = [];

        foreach ($window as $row) {
            if ($row['type'] === 'group') {
                $rows[] = $row;

                continue;
            }

            $own = array_values(array_filter($instances, fn (EventInstance $i) => self::belongs($i, $row['resource'])));
            [$events, $levels] = self::placeRow($own, $axis, $axisSeconds, $slotSeconds, $options);
            $rows[] = [...$row, 'events' => $events, 'levels' => $levels];
        }

        $nowOffset = $now->ts - $axis->start->ts;

        return new self(
            range: $range,
            slots: self::slots($axis, $slotSeconds),
            slotSeconds: $slotSeconds,
            axisSeconds: $axisSeconds,
            rows: $rows,
            totalRows: $total,
            rowsBefore: $start,
            rowsAfter: max(0, $total - $start - count($window)),
            nowLeft: $nowOffset >= 0 && $nowOffset < $axisSeconds ? round($nowOffset / $axisSeconds * 100, 4) : null,
        );
    }

    /**
     * Las filas en orden, sin eventos: grupos (por `group`) y recursos en árbol.
     *
     * @return list<array>
     */
    public static function rowSkeleton(?ResourceSet $resources): array
    {
        if ($resources === null || $resources->isEmpty()) {
            // Sin recursos, una sola fila con todos los eventos.
            return [['type' => 'resource', 'key' => 'all', 'resource' => null, 'depth' => 0, 'group' => null]];
        }

        $flat = $resources->flatten();
        $grouped = array_filter($flat, fn ($e) => $e['resource']->group !== null) !== [];

        if (! $grouped) {
            return array_map(fn ($e) => self::resourceRow($e['resource'], $e['depth']), $flat);
        }

        // Grupos en el orden de su primera aparición; dentro, el orden del árbol.
        $byGroup = [];
        foreach ($flat as $e) {
            $byGroup[$e['resource']->group ?? ''][] = $e;
        }

        $rows = [];
        foreach ($byGroup as $group => $entries) {
            $rows[] = ['type' => 'group', 'key' => 'g:'.$group, 'label' => (string) $group, 'count' => count($entries)];

            foreach ($entries as $e) {
                $rows[] = self::resourceRow($e['resource'], $e['depth']);
            }
        }

        return $rows;
    }

    private static function resourceRow(CalendarResource $r, int $depth): array
    {
        return ['type' => 'resource', 'key' => 'r:'.$r->id, 'resource' => $r, 'depth' => $depth, 'group' => $r->group];
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
     * @param  list<EventInstance>  $instances
     * @return array{0: list<array>, 1: int}
     */
    private static function placeRow(array $instances, DateRange $axis, int $axisSeconds, int $slotSeconds, LayoutOptions $options): array
    {
        $instances = $options->order->sort($instances);
        $sources = [];

        // Un evento nunca más estrecho que un cuarto de ranura: sin esto, uno de
        // un minuto en un timeline semanal sería invisible y no chocaría con nadie.
        $minWidth = max(1, intdiv($slotSeconds, 4));

        foreach ($instances as $order => $instance) {
            $part = $instance->range->intersect($axis);

            if ($part === null) {
                continue;
            }

            $start = $part->start->ts - $axis->start->ts;
            $end = min($axisSeconds, max($part->end->ts - $axis->start->ts, $start + $minWidth));

            $sources[] = new Source(
                key: $instance->instanceId,
                start: $start,
                end: $end,
                isStart: $part->start->equals($instance->range->start),
                isEnd: $part->end->equals($instance->range->end),
                orderIndex: $order,
                payload: $instance,
            );
        }

        $levels = PlacementKernel::buildLevels($sources, $options->orderStrict)->levels;
        $events = [];
        $pct = fn (float $s) => round($s / max(1, $axisSeconds) * 100, 4);

        foreach ($levels as $level => $items) {
            foreach ($items as $source) {
                $events[] = [
                    'instance' => $source->payload,
                    'key' => $source->key,
                    'left' => $pct($source->start),
                    'width' => $pct($source->end - $source->start),
                    'level' => $level,
                    'isStart' => $source->isStart,
                    'isEnd' => $source->isEnd,
                ];
            }
        }

        // Orden DOM temporal (lectores de pantalla), no por subfila.
        usort($events, fn ($a, $b) => [$a['left'], $a['level']] <=> [$b['left'], $b['level']]);

        return [$events, max(1, count($levels))];
    }

    /** @return list<array{start: int, label: Marker, isMajor: bool}> */
    private static function slots(DateRange $axis, int $slotSeconds): array
    {
        $slots = [];

        for ($t = 0; $t < $axis->seconds(); $t += $slotSeconds) {
            $label = $axis->start->addSeconds($t);
            $slots[] = ['start' => $t, 'label' => $label, 'isMajor' => $label->isStartOfDay()];
        }

        return $slots;
    }
}
