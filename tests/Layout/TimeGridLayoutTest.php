<?php

use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Layout\LayoutOptions;
use KoreUi\Calendar\Layout\TimeGridLayout;
use KoreUi\Calendar\Resources\ResourceSet;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\ViewRange;
use KoreUi\Calendar\Time\WallClock;

$now = Marker::parse('2026-10-16 10:30');
$week = fn () => ViewRange::for('week', Marker::parse('2026-10-16'));
$withResources = fn (string|int $id, string $start, string $end, array $resources) => (new EventNormalizer(new WallClock('UTC')))
    ->normalize(CalendarEvent::make(id: $id, title: (string) $id, start: $start, end: $end, resourceIds: $resources));

it('siete columnas, una por día, con su eje en porcentajes', function () use ($now, $week) {
    // 10:00-11:30 en un eje de 24 h: top 41,6667 %, alto 6,25 %.
    $g = TimeGridLayout::build($week(), [lyEvent('a', '2026-10-14 10:00', '2026-10-14 11:30')], $now);
    $item = $g->lanes[2]['events'][0];

    expect($g->columns)->toHaveCount(7)
        ->and($item['top'])->toBe(41.6667)
        ->and($item['height'])->toBe(6.25)
        ->and([$item['left'], $item['right']])->toBe([0.0, 0.0]);
});

it('dos eventos solapados se reparten el ancho', function () use ($now, $week) {
    $g = TimeGridLayout::build($week(), [
        lyEvent('a', '2026-10-14 10:00', '2026-10-14 12:00'),
        lyEvent('b', '2026-10-14 11:00', '2026-10-14 13:00'),
    ], $now, new LayoutOptions(slotEventOverlap: false));

    $lane = collect($g->lanes[2]['events'])->keyBy(fn ($e) => (string) $e['instance']->event->id);

    expect([$lane['a']['left'], $lane['a']['right']])->toBe([0.0, 50.0])
        ->and([$lane['b']['left'], $lane['b']['right']])->toBe([50.0, 0.0]);
});

it('la altura mínima se aplica antes de colisionar', function () use ($now, $week) {
    // Uno de 1 min y otro 2 min después: no se tocan en el tiempo, pero con un
    // mínimo de 15 min sí en pantalla, y van lado a lado (eventMinHeight.ts).
    $g = TimeGridLayout::build($week(), [
        lyEvent('a', '2026-10-14 10:00', '2026-10-14 10:01'),
        lyEvent('b', '2026-10-14 10:03', '2026-10-14 10:04'),
    ], $now, new LayoutOptions(slotEventOverlap: false));

    expect(collect($g->lanes[2]['events'])->pluck('right')->all())->toContain(50.0);
});

it('el carril de todo el día lleva los eventos de día completo', function () use ($now, $week) {
    $g = TimeGridLayout::build($week(), [lyEvent('v', '2026-10-13', '2026-10-16', allDay: true)], $now);

    expect($g->allDay['slices'])->toHaveCount(1)
        ->and([$g->allDay['slices'][0]['colStart'], $g->allDay['slices'][0]['colEnd']])->toBe([1, 4])
        ->and(collect($g->lanes)->flatMap(fn ($l) => $l['events']))->toBeEmpty();
});

it('el indicador de ahora cae en la columna de hoy', function () use ($now, $week) {
    $g = TimeGridLayout::build($week(), [], $now);

    expect($g->nowColumn)->toBe(4)->and($g->nowTop)->toBe(43.75);
});

it('slots relativos al inicio del eje', function () use ($now, $week) {
    $g = TimeGridLayout::build($week(), [], $now, new LayoutOptions(slotMin: 8 * 3600, slotMax: 10 * 3600), slotDuration: 1800);

    expect(array_map(fn ($s) => $s['label']->hour().':'.$s['label']->minute(), $g->slots))->toBe(['8:0', '8:30', '9:0', '9:30']);
});

it('por recurso: una columna por (recurso, día), y cada evento solo en la de su recurso', function () use ($now, $withResources) {
    $resources = new ResourceSet([['id' => 'a', 'title' => 'Sala A'], ['id' => 'b', 'title' => 'Sala B']]);
    $range = ViewRange::for('resource-day', Marker::parse('2026-10-16'));

    $g = TimeGridLayout::build($range, [
        $withResources('r1', '2026-10-16 09:00', '2026-10-16 10:00', ['a']),
        $withResources('r2', '2026-10-16 09:00', '2026-10-16 10:00', ['a', 'b']),
        lyEvent('sin', '2026-10-16 09:00', '2026-10-16 10:00'),
    ], $now, new LayoutOptions, $resources);

    $ids = fn (int $col) => collect($g->lanes[$col]['events'])->map(fn ($e) => (string) $e['instance']->event->id)->sort()->values()->all();

    expect($g->columns)->toHaveCount(2)
        ->and($g->columns[0]['resource']->title)->toBe('Sala A')
        ->and($ids(0))->toBe(['r1', 'r2'])
        ->and($ids(1))->toBe(['r2']);
});

it('fecha sobre recursos: un evento de día completo de varios días se parte por columna', function () use ($now) {
    $resources = new ResourceSet([['id' => 'a', 'title' => 'A'], ['id' => 'b', 'title' => 'B']]);
    $range = ViewRange::for('resource-week', Marker::parse('2026-10-16'));
    $event = (new EventNormalizer(new WallClock('UTC')))->normalize(
        CalendarEvent::make(id: 'v', title: 'V', start: '2026-10-12', end: '2026-10-14', allDay: true, resourceIds: ['a'])
    );

    $above = TimeGridLayout::build($range, [$event], $now, new LayoutOptions, $resources, datesAboveResources: true);
    $below = TimeGridLayout::build($range, [$event], $now, new LayoutOptions, $resources, datesAboveResources: false);

    // Fecha arriba: columnas lun-A, lun-B, mar-A… → las de A no son contiguas.
    expect(array_map(fn ($s) => [$s['colStart'], $s['colEnd']], $above->allDay['slices']))->toBe([[0, 1], [2, 3]])
        // Recurso arriba: A ocupa las columnas 0-6 seguidas → una sola barra.
        ->and(array_map(fn ($s) => [$s['colStart'], $s['colEnd']], $below->allDay['slices']))->toBe([[0, 2]]);
});

it('hourEvery(): cada cuántas ranuras cae una hora en punto del eje', function () use ($now, $week) {
    $every = fn (int $slotDuration) => TimeGridLayout::build($week(), [], $now, new LayoutOptions, null, false, $slotDuration)->hourEvery();

    // Con la ranura de 30 min la hora cae cada 2; con 15, cada 4; con 20, cada 3; con 1 h o más, en cada una.
    expect($every(1800))->toBe(2)
        ->and($every(900))->toBe(4)
        ->and($every(1200))->toBe(3)
        ->and($every(600))->toBe(6)
        ->and($every(3600))->toBe(1)
        ->and($every(7200))->toBe(1)
        // 45 min: 3 600 solo divide a 4 × 2 700 = 10 800, el múltiplo común más cercano.
        ->and($every(2700))->toBe(4)
        ->and($every(5400))->toBe(2);
});

it('hourEvery() coincide con las ranuras cuya hora relativa es en punto', function () use ($now, $week) {
    foreach ([300, 600, 900, 1200, 1800, 2700, 3600, 5400, 7200] as $slotDuration) {
        $g = TimeGridLayout::build($week(), [], $now, new LayoutOptions, null, false, $slotDuration);
        $hourly = array_keys(array_filter($g->slots, fn ($slot) => $slot['start'] % 3600 === 0));

        // Las ranuras con la hora en punto son exactamente 0, E, 2E… (lo que dibuja el degradado).
        expect($hourly)->toBe(array_values(array_filter(array_keys($g->slots), fn ($k) => $k % $g->hourEvery() === 0)), "ranura de {$slotDuration} s");
    }
});
