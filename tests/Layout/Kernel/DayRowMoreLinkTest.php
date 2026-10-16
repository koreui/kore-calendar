<?php

/*
 * Ejemplos oficiales del enlace "+N más" de FullCalendar, expresados sobre el
 * placer. NO son un port 1:1: en FullCalendar son tests de navegador
 * (packages/vanilla-tests/src/legacy/more-link.ts, 'dayMaxEventRows: 3' sobre la
 * primera semana de agosto de 2014, dom 27-jul = col 0), y aquí se comprueban
 * sobre el modelo (columna, número del enlace, trozos visibles). Los valores
 * salen de sus aserciones (docs/calendar.md §16; fc-edge-cases §1.6).
 */

use KoreUi\Calendar\Layout\Kernel\DayRowPlacer;
use KoreUi\Calendar\Layout\Kernel\DayRowPlacement;
use KoreUi\Calendar\Tests\Layout\Kernel\Support\Fixtures;

/** `[columna => número del enlace]` solo de las celdas con enlace. */
function moreLinkCounts(DayRowPlacement $placement): array
{
    $counts = [];

    foreach ($placement->cells as $column => $cell) {
        if ($cell->hiddenCount() > 0) {
            $counts[$column] = $cell->hiddenCount();
        }
    }

    return $counts;
}

// more-link.ts > 'doesn't display a more link when limit is more than the # of events'
// y 'when limit equal to the # of events'
it('no muestra enlace con 2 ni con 3 eventos bajo dayMaxEventRows: 3', function (int $events) {
    $spans = array_map(fn (int $i) => Fixtures::span("e{$i}", 2, 3), range(1, $events));
    $placement = DayRowPlacer::place($spans, 7, null, 3);

    expect(moreLinkCounts($placement))->toBe([])
        ->and($placement->visible)->toHaveCount($events);
})->with([2, 3]);

// more-link.ts > 'displays a more link when limit is less than the # of events'
it('con 4 eventos y 3 filas, el enlace ocupa la tercera: 2 visibles y "+2"', function () {
    $spans = array_map(fn (int $i) => Fixtures::span("e{$i}", 2, 3), range(1, 4));
    $placement = DayRowPlacer::place($spans, 7, null, 3);

    expect($placement->visible)->toHaveCount(2)
        ->and(moreLinkCounts($placement))->toBe([2 => 2]);
});

// more-link.ts > 'displays one more per day, when a multi-day event is above'
it('4 eventos de 2 días con 3 filas dan un enlace "+2" POR CELDA, no por grupo', function () {
    $spans = array_map(fn (int $i) => Fixtures::span("e{$i}", 2, 4), range(1, 4));
    $placement = DayRowPlacer::place($spans, 7, null, 3);

    expect(moreLinkCounts($placement))->toBe([2 => 2, 3 => 2]);
});

// more-link.ts > 'will render a pertially hidden single-day event'
it('3 eventos de 2 días y uno de 1 día: 3 visibles y un solo "+2" en la primera columna', function () {
    $placement = DayRowPlacer::place([
        Fixtures::span('event1', 2, 4),
        Fixtures::span('event2', 2, 4),
        Fixtures::span('event3', 2, 4),
        Fixtures::span('event4', 2, 3),
    ], 7, null, 3);

    expect($placement->visible)->toHaveCount(3)
        ->and(moreLinkCounts($placement))->toBe([2 => 2])
        // event3 sigue visible en la segunda columna: un trozo parcial.
        ->and(Fixtures::visibleKeys($placement))->toContain('event3:2:3:slice');
});

// more-link.ts > 'will render a partially multi-day hidden event' (FullCalendar #6187)
it('#6187: un evento de varios días oculto solo a medias se ve en la columna que cabe', function () {
    $placement = DayRowPlacer::place([
        Fixtures::span('event1', 1, 3),
        Fixtures::span('event2', 1, 3),
        Fixtures::span('event3', 1, 3),
        Fixtures::span('event4', 2, 4),
    ], 7, null, 3);

    expect($placement->visible)->toHaveCount(4)
        ->and(moreLinkCounts($placement))->toBe([2 => 2])
        ->and(Fixtures::hiddenIds($placement->cells[2]))->toBe(['event3', 'event4']);
});

// more-link.ts > 'will render a link in place of a hidden single day event, if covered by a multi-day'
it('un evento de un día tapado por uno de varios días cede su sitio al enlace', function () {
    $placement = DayRowPlacer::place([
        Fixtures::span('event1', 1, 3),
        Fixtures::span('event2', 1, 3),
        Fixtures::span('event3', 1, 2),
        Fixtures::span('event4', 1, 2),
    ], 7, null, 3);

    expect(moreLinkCounts($placement))->toBe([1 => 2]);
});

// more-link.ts > '... if covered by a multi-day and in its second column'
it('ídem cuando el evento de un día está en la segunda columna del de varios días', function () {
    $placement = DayRowPlacer::place([
        Fixtures::span('event1', 1, 3),
        Fixtures::span('event2', 1, 3),
        Fixtures::span('event3', 2, 3),
        Fixtures::span('event4', 2, 3),
    ], 7, null, 3);

    expect(moreLinkCounts($placement))->toBe([2 => 2]);
});

// more-link.ts > 'when auto' > 'in week view' > 'behaves as if limit is 5'
// (el carril de todo el día convierte `dayMaxEvents: true` en `dayMaxEventRows = 5`)
it('el carril de todo el día con 5 filas: 7 eventos dan 4 visibles y "+3"', function () {
    $spans = array_map(fn (int $i) => Fixtures::span("e{$i}", 2, 3), range(1, 7));
    $placement = DayRowPlacer::place($spans, 7, null, 5);

    expect($placement->visible)->toHaveCount(4)
        ->and(moreLinkCounts($placement))->toBe([2 => 3]);
});

// Los tres modos de límite con los mismos 3 eventos (tabla 1.6 del catálogo).
it('aplica la precedencia de modos: maxEvents gana, solo maxEventRows cobra una fila, ninguno es ilimitado', function () {
    $stack = array_map(fn (int $i) => Fixtures::span("e{$i}", 0, 1), range(1, 3));

    $unlimited = DayRowPlacer::place($stack, 1);
    $events = DayRowPlacer::place($stack, 1, 2);            // 2 eventos + el enlace
    $rows = DayRowPlacer::place($stack, 1, null, 2);        // 2 filas = 1 evento + el enlace
    $both = DayRowPlacer::place($stack, 1, 2, 1);           // gana maxEvents

    expect(count($unlimited->visible))->toBe(3)
        ->and(moreLinkCounts($unlimited))->toBe([])
        ->and(count($events->visible))->toBe(2)
        ->and(moreLinkCounts($events))->toBe([0 => 1])
        ->and(count($rows->visible))->toBe(1)
        ->and(moreLinkCounts($rows))->toBe([0 => 2])
        ->and(Fixtures::visibleKeys($both))->toBe(Fixtures::visibleKeys($events));
});

// FullCalendar #7462: el reparto de extras no recurre aunque haya muchas filas ocultas.
it('#7462: reparte muchos extras con dayMaxEventRows sin recursión ni bucle', function () {
    $spans = [];
    for ($i = 0; $i < 400; $i++) {
        $start = $i % 7;
        $spans[] = Fixtures::span("e{$i}", $start, min(7, $start + 1 + $i % 4));
    }

    $placement = DayRowPlacer::place($spans, 7, null, 6);

    expect($placement->rowCount)->toBeLessThanOrEqual(6)
        ->and(array_sum(array_map(fn ($c) => $c->hiddenCount(), $placement->cells)))->toBeGreaterThan(0);
});
