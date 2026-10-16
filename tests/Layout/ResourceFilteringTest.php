<?php

use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Layout\LayoutOptions;
use KoreUi\Calendar\Layout\ResourceIndex;
use KoreUi\Calendar\Layout\TimeGridLayout;
use KoreUi\Calendar\Layout\TimelineLayout;
use KoreUi\Calendar\Layout\TimeSlicer;
use KoreUi\Calendar\Resources\ResourceSet;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\ViewRange;
use KoreUi\Calendar\Time\WallClock;

/*
| Las vistas por recurso ya no filtran la lista entera de eventos por cada fila o columna, ni cortan
| cada evento contra todas las columnas: usan un índice por recurso y cortan solo contra las columnas
| del evento. El resultado tiene que ser el de filtrar: aquí cada fila o columna se compara con el
| layout del MISMO recurso construido a partir de los eventos que "pertenecen" según la regla de antes
| (algún `resourceIds` igual, como texto), sin recursos y sin ningún índice.
*/

/** Eventos al azar de la semana del 12 de octubre: con hora y de día completo, con 0 a 3 recursos (repetidos incluidos). */
function rfEvents(int $seed, int $count): array
{
    mt_srand($seed);
    $resources = [[], ['a'], ['b'], ['c'], ['a', 'b'], ['c', 'a'], ['b', 'b'], ['a', 'a', 'c'], ['zzz'], ['a', 'zzz']];
    $normalizer = new EventNormalizer(new WallClock('UTC'));
    $instances = [];

    for ($n = 1; $n <= $count; $n++) {
        $day = mt_rand(12, 18);
        $res = $resources[mt_rand(0, count($resources) - 1)];

        if (mt_rand(1, 6) === 1) {
            $event = CalendarEvent::make(id: $n, title: "E{$n}", start: sprintf('2026-10-%02d', $day), end: sprintf('2026-10-%02d', min(19, $day + mt_rand(1, 3))), allDay: true, resourceIds: $res);
        } else {
            $start = mktime(mt_rand(5, 21), mt_rand(0, 3) * 15, 0, 10, $day, 2026);
            $event = CalendarEvent::make(id: $n, title: "E{$n}", start: gmdate('Y-m-d H:i', $start), end: gmdate('Y-m-d H:i', $start + mt_rand(1, 40) * 900), resourceIds: $res);
        }

        $instances[] = $normalizer->normalize($event);
    }

    return $instances;
}

/** La regla de antes: el evento es de un recurso si alguno de sus ids es igual, como texto. */
function rfOf(array $instances, string $resource): array
{
    return array_values(array_filter($instances, fn (EventInstance $i) => in_array($resource, array_map('strval', $i->event->resourceIds), true)));
}

function rfResources(): ResourceSet
{
    return new ResourceSet([['id' => 'a', 'title' => 'A'], ['id' => 'b', 'title' => 'B'], ['id' => 'c', 'title' => 'C']]);
}

// ── ResourceIndex ──────────────────────────────────────────────────────

it('el índice por recurso conserva el orden y no repite un evento en un recurso', function () {
    $instances = [
        lyEvent(1, '2026-10-12 10:00', '2026-10-12 11:00'),
        (new EventNormalizer(new WallClock('UTC')))->normalize(CalendarEvent::make(id: 2, title: '2', start: '2026-10-12 10:00', end: '2026-10-12 11:00', resourceIds: ['a', 'b', 'a'])),
        (new EventNormalizer(new WallClock('UTC')))->normalize(CalendarEvent::make(id: 3, title: '3', start: '2026-10-12 10:00', end: '2026-10-12 11:00', resourceIds: [7, 'b'])),
    ];

    $index = ResourceIndex::of($instances);
    $ids = fn (array $list) => array_map(fn (EventInstance $i) => $i->event->id, $list);

    expect(array_keys($index))->toBe(['a', 'b', 7])
        ->and($ids($index['a']))->toBe([2])
        ->and($ids($index['b']))->toBe([2, 3])
        // Una clave numérica se busca igual con el id como texto.
        ->and($ids($index[(string) 7]))->toBe([3]);
});

// ── Timeline ───────────────────────────────────────────────────────────

it('cada fila del timeline tiene los mismos eventos y colocación que el layout de sus eventos sueltos (semillas 1-25)', function () {
    $range = ViewRange::for('timeline-week', Marker::parse('2026-10-16'));
    $now = Marker::parse('2026-10-16 10:30');

    for ($seed = 1; $seed <= 25; $seed++) {
        $instances = rfEvents($seed, 60);
        $layout = TimelineLayout::build($range, $instances, $now, new LayoutOptions, rfResources(), 3600);

        foreach ($layout->rows as $row) {
            $alone = TimelineLayout::build($range, rfOf($instances, $row['resource']->id), $now, new LayoutOptions, null, 3600);
            $shape = fn (array $events) => array_map(fn ($e) => [$e['key'], $e['left'], $e['width'], $e['level'], $e['isStart'], $e['isEnd']], $events);

            expect($shape($row['events']))->toBe($shape($alone->rows[0]['events']), "semilla {$seed}, fila {$row['key']}")
                ->and($row['levels'])->toBe($alone->rows[0]['levels']);
        }
    }
});

it('el timeline sin recursos pinta todos los eventos, y los de un recurso que no existe no salen en ninguna fila', function () {
    $range = ViewRange::for('timeline-day', Marker::parse('2026-10-16'));
    $now = Marker::parse('2026-10-16 10:30');
    $instances = [
        lyEvent(1, '2026-10-16 09:00', '2026-10-16 10:00'),
        (new EventNormalizer(new WallClock('UTC')))->normalize(CalendarEvent::make(id: 2, title: '2', start: '2026-10-16 09:00', end: '2026-10-16 10:00', resourceIds: ['zzz'])),
    ];

    $sin = TimelineLayout::build($range, $instances, $now);
    $con = TimelineLayout::build($range, $instances, $now, new LayoutOptions, rfResources(), 3600);

    expect($sin->rows[0]['events'])->toHaveCount(2)
        ->and(array_sum(array_map(fn ($r) => count($r['events']), $con->rows)))->toBe(0);
});

// ── Rejilla horaria por recurso ────────────────────────────────────────

it('cada columna de la rejilla por recurso tiene lo mismo que el layout de los eventos de su recurso (semillas 1-25)', function () {
    $range = ViewRange::for('resource-week', Marker::parse('2026-10-16'));
    $now = Marker::parse('2026-10-16 10:30');
    $resources = ['a', 'b', 'c'];

    for ($seed = 1; $seed <= 25; $seed++) {
        $instances = rfEvents($seed, 60);

        foreach ([false, true] as $datesAbove) {
            $layout = TimeGridLayout::build($range, $instances, $now, new LayoutOptions, rfResources(), $datesAbove);

            foreach ($resources as $r => $resource) {
                $alone = TimeGridLayout::build($range, rfOf($instances, $resource), $now, new LayoutOptions);
                $shape = fn (array $lane) => [
                    array_map(fn ($e) => [$e['instance']->instanceId, $e['top'], $e['height'], $e['left'], $e['right'], $e['zIndex'], $e['marginEnd'], $e['isShort'], $e['segment']->isStart, $e['segment']->isEnd], $lane['events']),
                    array_map(fn ($h) => [$h['top'], $h['height'], array_map(fn ($i) => $i->instanceId, $h['instances'])], $lane['hidden']),
                ];

                foreach ($alone->lanes as $day => $lane) {
                    // Resource-major: las 7 columnas de cada recurso seguidas; fecha arriba: un día y luego sus 3 recursos.
                    $column = $datesAbove ? $day * 3 + $r : $r * 7 + $day;

                    expect($shape($layout->lanes[$column]))->toBe($shape($lane), "semilla {$seed}, recurso {$resource}, día {$day}, fecha arriba ".var_export($datesAbove, true));
                }
            }
        }
    }
});

it('el carril de todo el día por recurso coloca cada evento solo en las columnas de sus recursos (semillas 1-25)', function () {
    $range = ViewRange::for('resource-week', Marker::parse('2026-10-16'));
    $now = Marker::parse('2026-10-16 10:30');

    for ($seed = 1; $seed <= 25; $seed++) {
        $instances = rfEvents($seed, 60);
        $layout = TimeGridLayout::build($range, $instances, $now, new LayoutOptions, rfResources());

        foreach (['a', 'b', 'c'] as $r => $resource) {
            $alone = TimeGridLayout::build($range, rfOf($instances, $resource), $now, new LayoutOptions);
            // Resource-major: las columnas de este recurso son de r*7 a r*7+6.
            $own = array_values(array_filter($layout->allDay['slices'], fn ($s) => $s['colStart'] >= $r * 7 && $s['colStart'] < $r * 7 + 7));
            $shape = fn (array $slices, int $offset) => array_map(fn ($s) => [$s['instance']->instanceId, $s['colStart'] - $offset, $s['colEnd'] - $offset, $s['isStart'], $s['isEnd']], $slices);

            $sort = function (array $rows) {
                usort($rows, fn ($x, $y) => [$x[1], $x[0]] <=> [$y[1], $y[0]]);

                return $rows;
            };

            expect($sort($shape($own, $r * 7)))->toBe($sort($shape($alone->allDay['slices'], 0)), "semilla {$seed}, recurso {$resource}");
        }

        // Y ningún trozo cae fuera de las columnas de un recurso con el evento: el total es la suma de los de cada recurso.
        $total = 0;
        foreach (['a', 'b', 'c'] as $resource) {
            $total += count(TimeGridLayout::build($range, rfOf($instances, $resource), $now, new LayoutOptions)->allDay['slices']);
        }
        expect(count($layout->allDay['slices']))->toBe($total);
    }
});

it('en una vista por recurso los eventos sin recurso no salen, y en la semana normal salen todos', function () {
    $range = ViewRange::for('resource-day', Marker::parse('2026-10-14'));
    $now = Marker::parse('2026-10-14 10:30');
    $instances = [lyEvent(1, '2026-10-14 09:00', '2026-10-14 10:00'), lyEvent(2, '2026-10-14', allDay: true)];

    $porRecurso = TimeGridLayout::build($range, $instances, $now, new LayoutOptions, rfResources());
    $semana = TimeGridLayout::build(ViewRange::for('week', Marker::parse('2026-10-14')), $instances, $now);

    expect(array_sum(array_map(fn ($l) => count($l['events']), $porRecurso->lanes)))->toBe(0)
        ->and($porRecurso->allDay['slices'])->toBe([])
        ->and(array_sum(array_map(fn ($l) => count($l['events']), $semana->lanes)))->toBe(1)
        ->and($semana->allDay['slices'])->toHaveCount(1);
});

// ── TimeSlicer ─────────────────────────────────────────────────────────

/** `TimeSlicer::slice()` antes de la aritmética entera: un DateRange por (evento, columna). */
function rfReferenceSlice(array $columns, int $slotMin, int $slotMax, EventInstance $instance): array
{
    if ($instance->allDay) {
        return [];
    }

    $segments = [];

    foreach (array_keys($columns) as $column) {
        $range = new DateRange($columns[$column]->addSeconds($slotMin), $columns[$column]->addSeconds($slotMax));
        $part = $instance->range->intersect($range);

        if ($part === null) {
            continue;
        }

        $segments[] = [$column, $part->start->ts - $range->start->ts, $part->end->ts - $range->start->ts, $part->start->equals($instance->range->start), $part->end->equals($instance->range->end)];
    }

    return $segments;
}

it('el corte con enteros da los mismos trozos que el corte con rangos, con ejes que se solapan o se salen del día', function () {
    $columns = lyDays('2026-10-12', 7);
    $instances = rfEvents(5, 80);

    foreach ([[0, 86400], [-7200, 86400], [0, 26 * 3600], [8 * 3600, 18 * 3600], [-3600, 30 * 3600]] as [$min, $max]) {
        $slicer = new TimeSlicer($columns, $min, $max);

        foreach ($instances as $instance) {
            $real = array_map(fn ($s) => [$s->column, $s->start, $s->end, $s->isStart, $s->isEnd], $slicer->slice($instance));

            expect($real)->toBe(rfReferenceSlice($columns, $min, $max, $instance), "eje {$min}..{$max}, evento {$instance->instanceId}");
        }
    }
});

it('un evento que acaba justo donde empieza el eje, o empieza justo donde acaba, no deja un trozo vacío', function () {
    $dia = new TimeSlicer(lyDays('2026-10-12', 2));
    $recortado = new TimeSlicer(lyDays('2026-10-12', 1), 8 * 3600, 18 * 3600);

    expect(array_map(fn ($s) => [$s->column, $s->start, $s->end], $dia->slice(lyEvent(1, '2026-10-12 22:00', '2026-10-13 00:00'))))->toBe([[0, 79200, 86400]])
        ->and($recortado->slice(lyEvent(2, '2026-10-12 06:00', '2026-10-12 08:00')))->toBe([])
        ->and($recortado->slice(lyEvent(3, '2026-10-12 18:00', '2026-10-12 19:00')))->toBe([]);
});

it('slice() con $only corta solo contra esas columnas', function () {
    $slicer = new TimeSlicer(lyDays('2026-10-12', 3));
    $largo = lyEvent(1, '2026-10-12 22:00', '2026-10-14 02:00');

    expect(array_map(fn ($s) => $s->column, $slicer->slice($largo, [0, 2])))->toBe([0, 2])
        ->and($slicer->slice($largo, []))->toBe([])
        ->and(array_map(fn ($s) => $s->column, $slicer->slice($largo)))->toBe([0, 1, 2]);
});
