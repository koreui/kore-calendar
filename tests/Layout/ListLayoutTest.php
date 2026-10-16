<?php

use KoreUi\Calendar\Layout\ListLayout;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\ViewRange;

it('solo los días con eventos, y cada evento con su tipo de hora ese día', function () {
    $l = ListLayout::build(ViewRange::for('list', Marker::parse('2026-10-16')), [
        lyEvent('noche', '2026-10-13 22:00', '2026-10-15 02:00'),
        lyEvent('daily', '2026-10-13 10:00', '2026-10-13 10:30'),
        lyEvent('fiesta', '2026-10-16', allDay: true),
    ], Marker::parse('2026-10-16 09:00'));

    $resumen = array_map(fn ($d) => [
        $d['date']->toDateString(),
        array_map(fn ($i) => $i['instance']->event->id.':'.$i['kind'], $d['items']),
    ], $l->days);

    expect($resumen)->toBe([
        ['2026-10-13', ['daily:single', 'noche:start']],
        ['2026-10-14', ['noche:middle']],
        ['2026-10-15', ['noche:end']],
        ['2026-10-16', ['fiesta:all-day']],
    ])->and($l->days[3]['isToday'])->toBeTrue();
});

it('sin eventos, sin días', function () {
    expect(ListLayout::build(ViewRange::for('list', Marker::parse('2026-10-16')), [], Marker::parse('2026-10-16'))->days)->toBe([]);
});
