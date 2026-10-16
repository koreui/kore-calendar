<?php

use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\ViewOptions;
use KoreUi\Calendar\Time\ViewRange;
use KoreUi\Calendar\Time\WeekRules;

function rango(KoreUi\Calendar\Time\DateRange $r): string
{
    return $r->start->toDateString().' → '.$r->end->toDateString();
}

it('mes: rellena a semanas completas y a 6 filas con fixedWeekCount', function () {
    // Julio de 2014 ocupa 5 semanas (FullCalendar: 6 filas con fixedWeekCount, 5 sin él).
    $sixRows = ViewRange::for('month', Marker::parse('2014-07-15'), new ViewOptions(new WeekRules(0, 6)));
    $fiveRows = ViewRange::for('month', Marker::parse('2014-07-15'), new ViewOptions(new WeekRules(0, 6), fixedWeekCount: false));

    expect(rango($sixRows->current))->toBe('2014-07-01 → 2014-08-01')
        ->and(rango($sixRows->render))->toBe('2014-06-29 → 2014-08-10')
        ->and(count($sixRows->days()) / 7)->toBe(6)
        ->and(count($fiveRows->days()) / 7)->toBe(5);
});

it('mes de 6 semanas tiene 6 filas con y sin fixedWeekCount', function () {
    $r = ViewRange::for('month', Marker::parse('2014-08-10'), new ViewOptions(new WeekRules(0, 6), fixedWeekCount: false));

    expect(count($r->days()) / 7)->toBe(6);
});

it('showNonCurrentDates = false recorta el rango activo al mes', function () {
    $r = ViewRange::for('month', Marker::parse('2026-10-16'), new ViewOptions(showNonCurrentDates: false));

    expect(rango($r->active))->toBe('2026-10-01 → 2026-11-01')
        ->and(rango($r->render))->toBe('2026-09-28 → 2026-11-09');
});

it('semana y día', function () {
    $o = new ViewOptions(new WeekRules(1, 4));

    expect(rango(ViewRange::for('week', Marker::parse('2026-10-18 15:00'), $o)->render))->toBe('2026-10-12 → 2026-10-19')
        ->and(rango(ViewRange::for('day', Marker::parse('2026-10-16 15:00'), $o)->render))->toBe('2026-10-16 → 2026-10-17')
        ->and(rango(ViewRange::for('resource-week', Marker::parse('2026-10-16'), $o)->render))->toBe('2026-10-12 → 2026-10-19');
});

it('navega truncando antes de sumar', function () {
    $m = ViewRange::for('month', Marker::parse('2026-01-31'));

    expect($m->next()->toDateString())->toBe('2026-02-01')
        ->and($m->previous()->toDateString())->toBe('2025-12-01')
        ->and(ViewRange::for('week', Marker::parse('2026-10-16'))->next()->toDateString())->toBe('2026-10-19')
        ->and(ViewRange::for('day', Marker::parse('2026-10-16'))->previous()->toDateString())->toBe('2026-10-15');
});

it('fin de semana oculto: recorta la semana y la vista día salta los ocultos', function () {
    $o = new ViewOptions(hiddenDays: [0, 6]);

    $week = ViewRange::for('week', Marker::parse('2026-10-16'), $o);
    $saturday = ViewRange::for('day', Marker::parse('2026-10-17'), $o);
    $friday = ViewRange::for('day', Marker::parse('2026-10-16'), $o);
    $monday = ViewRange::for('day', Marker::parse('2026-10-19'), $o);

    expect(rango($week->render))->toBe('2026-10-12 → 2026-10-17')
        ->and(count($week->days()))->toBe(5)
        ->and($saturday->date->toDateString())->toBe('2026-10-19')
        ->and($friday->next()->toDateString())->toBe('2026-10-19')
        ->and($monday->previous()->toDateString())->toBe('2026-10-16');
});

it('día oculto en medio de la semana: no recorta el rango, solo deja de ser un día visible', function () {
    $r = ViewRange::for('week', Marker::parse('2026-10-16'), new ViewOptions(hiddenDays: [3]));

    expect(rango($r->render))->toBe('2026-10-12 → 2026-10-19')
        ->and(array_map(fn ($d) => $d->day(), $r->days()))->toBe([12, 13, 15, 16, 17, 18]);
});

it('no copia ni muta los días ocultos que recibe', function () {
    $hidden = [6, 0, 6];
    $o = new ViewOptions(hiddenDays: $hidden);

    expect($o->hiddenDays)->toBe([0, 6])->and($hidden)->toBe([6, 0, 6]);
});

it('rechaza vistas desconocidas y ocultar los siete días', function () {
    expect(fn () => ViewRange::for('agenda', Marker::parse('2026-10-16')))->toThrow(InvalidArgumentException::class, 'Vista desconocida')
        ->and(fn () => new ViewOptions(hiddenDays: [0, 1, 2, 3, 4, 5, 6]))->toThrow(InvalidArgumentException::class);
});

it('los rangos de mes no se mueven con el cambio de horario', function () {
    // Octubre de 2026 contiene el cambio de Madrid (25-oct). En markers no importa.
    $r = ViewRange::for('month', Marker::parse('2026-10-25'));

    expect(count($r->days()))->toBe(42)
        ->and($r->days()[27]->toDateString())->toBe('2026-10-25');
});
