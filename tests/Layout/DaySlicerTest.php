<?php

use KoreUi\Calendar\Layout\DaySlicer;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;

$seg = fn (array $segs) => array_map(fn ($s) => [$s->row, $s->colStart, $s->colEnd, $s->isStart, $s->isEnd], $segs);

it('corta un evento de varios días en un trozo por semana', function () use ($seg) {
    // Dos semanas desde el lunes 12-oct-2026; evento del viernes 16 al martes 20 (fin exclusivo 21).
    $rows = [lyDays('2026-10-12', 7), lyDays('2026-10-19', 7)];
    $slicer = new DaySlicer($rows, new DateRange(Marker::parse('2026-10-12'), Marker::parse('2026-10-26')));

    expect($seg($slicer->slice(lyEvent(1, '2026-10-16', '2026-10-21'))))->toBe([
        [0, 4, 7, true, false],
        [1, 0, 2, false, true],
    ]);
});

it('fin de semana oculto: recorta a las columnas visibles y pierde el inicio o el fin', function () use ($seg) {
    // Casos de vanilla-tests/legacy/DayGrid-events.ts, con semana lun-vie (5 columnas).
    $rows = [lyDays('2026-10-12', 5)];
    $framing = new DateRange(Marker::parse('2026-10-12'), Marker::parse('2026-10-17'));
    $slicer = new DaySlicer($rows, $framing);

    // Empieza el domingo 11 (fuera) y termina el miércoles 14 (excl): cols 0-1, sin inicio.
    expect($seg($slicer->slice(lyEvent(1, '2026-10-11', '2026-10-14'))))->toBe([[0, 0, 2, false, true]])
        // Del miércoles 14 al domingo 18 (termina en un día oculto): cols 2-4, sin fin.
        ->and($seg($slicer->slice(lyEvent(2, '2026-10-14', '2026-10-18'))))->toBe([[0, 2, 5, true, false]]);
});

it('día oculto en medio: el evento que empieza en él no tiene inicio', function () use ($seg) {
    // Martes oculto: columnas lun, mié, jue, vie, sáb, dom.
    $row = array_values(array_filter(lyDays('2026-10-12', 7), fn ($d) => $d->dayOfWeek() !== 2));
    $slicer = new DaySlicer([$row], new DateRange(Marker::parse('2026-10-12'), Marker::parse('2026-10-19')));

    expect($seg($slicer->slice(lyEvent(1, '2026-10-13', '2026-10-16'))))->toBe([[0, 1, 3, false, true]])
        ->and($slicer->slice(lyEvent(2, '2026-10-13', '2026-10-14')))->toBe([]);
});

it('un evento con hora que termina justo a medianoche no ocupa el día siguiente', function () use ($seg) {
    $slicer = new DaySlicer([lyDays('2026-10-12', 7)], new DateRange(Marker::parse('2026-10-12'), Marker::parse('2026-10-19')));

    expect($seg($slicer->slice(lyEvent(1, '2026-10-12 22:00', '2026-10-13 00:00'))))->toBe([[0, 0, 1, true, true]])
        ->and($seg($slicer->slice(lyEvent(2, '2026-10-12 22:00', '2026-10-13 00:00:01'))))->toBe([[0, 0, 2, true, true]]);
});

it('nextDayThreshold: terminar a la hora del umbral cuenta, un minuto antes no', function () {
    $r = fn (string $end) => DaySlicer::visibleDayRange(lyEvent(1, '2026-09-30 08:00', $end)->range, 9 * 3600);

    expect($r('2026-10-01 08:59')->end->toDateString())->toBe('2026-10-01')
        ->and($r('2026-10-01 09:00')->end->toDateString())->toBe('2026-10-02');
});

it('un evento fuera del rango activo no produce trozos, y uno recortado pierde inicio y fin', function () use ($seg) {
    $slicer = new DaySlicer([lyDays('2026-10-12', 7)], new DateRange(Marker::parse('2026-10-14'), Marker::parse('2026-10-16')));

    expect($slicer->slice(lyEvent(1, '2026-10-01', '2026-10-05')))->toBe([])
        ->and($seg($slicer->slice(lyEvent(2, '2026-10-12', '2026-10-19'))))->toBe([[0, 2, 4, false, false]]);
});
