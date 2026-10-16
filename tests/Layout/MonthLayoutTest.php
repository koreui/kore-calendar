<?php

use KoreUi\Calendar\Layout\LayoutOptions;
use KoreUi\Calendar\Layout\MonthLayout;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\ViewOptions;
use KoreUi\Calendar\Time\ViewRange;
use KoreUi\Calendar\Time\WeekRules;

$now = Marker::parse('2026-10-16 12:00');
$range = fn (array $opts = []) => ViewRange::for('month', Marker::parse('2026-10-16'), new ViewOptions(...$opts));

it('seis semanas de siete días, con hoy y los días de otro mes marcados', function () use ($now, $range) {
    $m = MonthLayout::build($range(), [], $now);

    expect($m->rows)->toHaveCount(6)
        ->and($m->columns)->toBe(7)
        ->and($m->rows[0]['days'][0]['date']->toDateString())->toBe('2026-09-28')
        ->and($m->rows[0]['days'][0]['isOther'])->toBeTrue()
        ->and($m->rows[2]['days'][4]['isToday'])->toBeTrue();
});

it('un evento de varios días ocupa sus columnas y se parte por semana', function () use ($now, $range) {
    // Del viernes 16 al martes 20 (fin exclusivo 21).
    $m = MonthLayout::build($range(), [lyEvent('congreso', '2026-10-16', '2026-10-21')], $now);

    $semana3 = $m->rows[2]['slices'];
    $semana4 = $m->rows[3]['slices'];

    expect($semana3)->toHaveCount(1)
        ->and([$semana3[0]['colStart'], $semana3[0]['colEnd'], $semana3[0]['isStart'], $semana3[0]['isEnd']])->toBe([4, 7, true, false])
        ->and([$semana4[0]['colStart'], $semana4[0]['colEnd'], $semana4[0]['isStart'], $semana4[0]['isEnd']])->toBe([0, 2, false, true])
        ->and($semana3[0]['display'])->toBe('block');
});

it('un evento con hora de un solo día es "punto + hora"; si cruza la medianoche, barra', function () use ($now, $range) {
    $m = MonthLayout::build($range(), [
        lyEvent('daily', '2026-10-13 10:00', '2026-10-13 10:30'),
        lyEvent('noche', '2026-10-13 22:00', '2026-10-14 02:00'),
    ], $now);

    $display = collect($m->rows[2]['slices'])->mapWithKeys(fn ($s) => [(string) $s['instance']->event->id => $s['display']]);

    expect($display->all())->toBe(['daily' => 'list-item', 'noche' => 'block']);
});

it('max_events: los que no caben se cuentan en el "+N más" de su celda y el popover los lista todos', function () use ($now, $range) {
    $events = array_map(fn ($i) => lyEvent("e{$i}", '2026-10-14', allDay: true), range(1, 5));
    $m = MonthLayout::build($range(), $events, $now, new LayoutOptions(maxEvents: 2));

    $miercoles = $m->rows[2]['days'][2];

    expect(count($m->rows[2]['slices']))->toBe(2)
        ->and($miercoles['hiddenCount'])->toBe(3)
        ->and($miercoles['popover'])->toHaveCount(5);
});

it('los números de semana salen de la primera celda de cada fila', function () use ($now, $range) {
    $m = MonthLayout::build($range(['weekRules' => WeekRules::iso()]), [], $now, new LayoutOptions, WeekRules::iso());

    expect(array_column($m->rows, 'weekNumber'))->toBe([40, 41, 42, 43, 44, 45]);
});

it('con showNonCurrentDates = false los días de otro mes quedan deshabilitados y sin eventos', function () use ($now, $range) {
    $m = MonthLayout::build($range(['showNonCurrentDates' => false]), [lyEvent('fin', '2026-09-28', allDay: true)], $now);

    expect($m->rows[0]['days'][0]['isDisabled'])->toBeTrue()
        ->and($m->rows[0]['slices'])->toBe([]);
});

it('los eventos de fondo no se colocan como barras', function () use ($now, $range) {
    $bg = (new KoreUi\Calendar\Events\EventNormalizer(new KoreUi\Calendar\Time\WallClock('UTC')))->normalize(
        KoreUi\Calendar\Events\CalendarEvent::make(id: 'bg', title: 'Fondo', start: '2026-10-14', display: 'background')
    );

    expect(MonthLayout::build($range(), [$bg], $now)->rows[2]['slices'])->toBe([]);
});
