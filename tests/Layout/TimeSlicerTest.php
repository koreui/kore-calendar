<?php

use KoreUi\Calendar\Layout\TimeSlicer;

$seg = fn (array $segs) => array_map(fn ($s) => [$s->column, $s->start / 3600, $s->end / 3600, $s->isStart, $s->isEnd], $segs);

it('un evento con hora de varios días se parte en un trozo por columna', function () use ($seg) {
    $slicer = new TimeSlicer(lyDays('2026-10-12', 3));

    expect($seg($slicer->slice(lyEvent(1, '2026-10-12 22:00', '2026-10-14 02:00'))))->toBe([
        [0, 22, 24, true, false],
        [1, 0, 24, false, false],
        [2, 0, 2, false, true],
    ]);
});

it('los eventos de día completo no van a las columnas', function () {
    expect((new TimeSlicer(lyDays('2026-10-12', 1)))->slice(lyEvent(1, '2026-10-12', allDay: true)))->toBe([]);
});

it('slotMax de 26 h: el evento de madrugada se pinta en su columna y en la anterior', function () use ($seg) {
    // FullCalendar event-render/maxTime.ts.
    $slicer = new TimeSlicer(lyDays('2026-10-12', 2), 0, 26 * 3600);

    expect($seg($slicer->slice(lyEvent(1, '2026-10-13 00:00', '2026-10-13 02:00'))))->toBe([
        [0, 24, 26, true, true],
        [1, 0, 2, true, true],
    ]);
});

it('slotMin negativo: el evento de las 22 se pinta también al principio del día siguiente', function () use ($seg) {
    $slicer = new TimeSlicer(lyDays('2026-10-12', 2), -2 * 3600, 24 * 3600);

    expect($seg($slicer->slice(lyEvent(1, '2026-10-12 22:00', '2026-10-13 00:00'))))->toBe([
        [0, 24, 26, true, true],
        [1, 0, 2, true, true],
    ]);
});

it('recorta al eje visible y conserva si el trozo es inicio o fin real', function () use ($seg) {
    $slicer = new TimeSlicer(lyDays('2026-10-12', 1), 8 * 3600, 18 * 3600);

    expect($seg($slicer->slice(lyEvent(1, '2026-10-12 07:00', '2026-10-12 09:00'))))->toBe([[0, 0, 1, false, true]])
        ->and($slicer->slice(lyEvent(2, '2026-10-12 19:00', '2026-10-12 20:00')))->toBe([])
        ->and($slicer->axisSeconds())->toBe(10 * 3600);
});
