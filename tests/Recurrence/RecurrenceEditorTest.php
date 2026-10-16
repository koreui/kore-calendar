<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventCollector;
use KoreUi\Calendar\Events\EventInstance;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Events\Sources\ArraySource;
use KoreUi\Calendar\Interaction\MutationBuilder;
use KoreUi\Calendar\Recurrence\RecurrenceEditor;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;

require_once __DIR__.'/../Interaction/helpers.php';

/*
| RecurrenceEditor: lo que el consumidor guarda para cada alcance. Se prueba contra
| la expansión de verdad: un EXDATE, un corte o una excepción valen si, al volver a
| expandir lo guardado, sale exactamente lo que el usuario pidió.
*/

/** Las instancias de unos eventos en un rango, ordenadas, con la vista en `$zone`. */
function reInstances(array $events, string $from = '2026-10-01', string $to = '2027-01-31', string $zone = 'Europe/Madrid'): array
{
    $clock = new WallClock($zone);
    $instances = (new EventCollector($clock, new EventNormalizer($clock)))
        ->collect([new ArraySource($events)], new DateRange(Marker::parse($from), Marker::parse($to)));
    usort($instances, fn ($a, $b) => $a->range->start->ts <=> $b->range->start->ts);

    return $instances;
}

/** Los inicios como 'Y-m-d H:i' de pared de la vista. */
function reStarts(array $instances, string $format = 'Y-m-d H:i'): array
{
    return array_map(fn (EventInstance $i) => gmdate($format, $i->range->start->ts), $instances);
}

// ── exclude ────────────────────────────────────────────────────────────

it('exclude: en una serie flotante añade la hora de pared, y la expansión deja de generarla', function () {
    $row = ['id' => 's', 'title' => 'S', 'start' => '2026-10-12 09:00', 'end' => '2026-10-12 10:00', 'rrule' => 'FREQ=DAILY;COUNT=5', 'exdates' => ['2026-10-13 09:00:00']];
    $series = CalendarEvent::fromArray($row);
    $day14 = reInstances([$row])[1];

    expect(reStarts([$day14]))->toBe(['2026-10-14 09:00'])
        ->and(RecurrenceEditor::exclude($series, $day14))->toBe(['2026-10-13 09:00:00', '2026-10-14 09:00:00']);

    $saved = [...$row, 'exdates' => RecurrenceEditor::exclude($series, $day14)];

    expect(reStarts(reInstances([$saved])))->toBe(['2026-10-12 09:00', '2026-10-15 09:00', '2026-10-16 09:00']);
});

it('exclude: en un día completo añade el día', function () {
    $row = ['id' => 's', 'title' => 'S', 'start' => '2020-12-01', 'allDay' => true, 'rrule' => 'FREQ=WEEKLY'];
    $dec15 = reInstances([$row], '2020-12-01', '2021-01-01')[2];

    $exdates = RecurrenceEditor::exclude(CalendarEvent::fromArray($row), $dec15);

    expect($exdates)->toBe(['2020-12-15'])
        ->and(reStarts(reInstances([[...$row, 'exdates' => $exdates]], '2020-12-01', '2021-01-01'), 'Y-m-d'))
        ->toBe(['2020-12-01', '2020-12-08', '2020-12-22', '2020-12-29']);
});

it('exclude: en una serie con zona añade el instante en la zona de la serie, aunque la vista sea otra', function () {
    // «Lunes 9:00 en Madrid», vista en Nueva York.
    $start = CarbonImmutable::parse('2026-10-19 09:00', 'Europe/Madrid');
    $row = ['id' => 's', 'title' => 'S', 'start' => $start, 'end' => $start->addHour(), 'rrule' => 'FREQ=WEEKLY;COUNT=4'];
    $second = reInstances([$row], zone: 'America/New_York')[1];

    $exdates = RecurrenceEditor::exclude(CalendarEvent::fromArray($row), $second);

    expect($exdates)->toHaveCount(1)
        ->and($exdates[0])->toBeInstanceOf(DateTimeInterface::class)
        ->and($exdates[0]->getTimezone()->getName())->toBe('Europe/Madrid')
        ->and($exdates[0]->format('c'))->toBe('2026-10-26T09:00:00+01:00');

    expect(reStarts(reInstances([[...$row, 'exdates' => $exdates]], zone: 'America/New_York')))
        ->toBe(['2026-10-19 03:00', '2026-11-02 03:00', '2026-11-09 03:00']);
});

it('exclude: conserva los EXDATE que ya había y acepta la mutación como ocurrencia', function () {
    $row = ['id' => 's', 'title' => 'S', 'start' => '2026-10-12 09:00', 'rrule' => 'FREQ=DAILY;COUNT=5', 'exdates' => ['2026-10-12 09:00:00']];
    $series = CalendarEvent::fromArray($row);
    $occ = reInstances([$row])[0];   // el 13: el 12 ya estaba excluido

    $mutation = MutationBuilder::fromSettings(ixSettings())->move($occ, Marker::parse('2026-10-13 15:00'), false);

    expect($mutation->isOccurrence())->toBeTrue()
        ->and($mutation->seriesId)->toBe('s')
        ->and(RecurrenceEditor::exclude($series, $mutation))->toBe(['2026-10-12 09:00:00', '2026-10-13 09:00:00']);
});

it('rechaza lo que no es una ocurrencia de esa serie', function () {
    $row = ['id' => 's', 'title' => 'S', 'start' => '2026-10-12 09:00', 'rrule' => 'FREQ=DAILY;COUNT=2'];
    $other = ['id' => 't', 'title' => 'T', 'start' => '2026-10-12 09:00', 'rrule' => 'FREQ=DAILY;COUNT=2'];
    $series = CalendarEvent::fromArray($row);
    $plain = (new EventNormalizer(new WallClock('UTC')))->normalize(CalendarEvent::make(id: 'p', title: 'P', start: '2026-10-12 09:00'));

    expect(fn () => RecurrenceEditor::exclude($series, $plain))->toThrow(InvalidArgumentException::class, 'no es una ocurrencia')
        ->and(fn () => RecurrenceEditor::exclude($series, reInstances([$other])[0]))->toThrow(InvalidArgumentException::class, 'serie «t», no de «s»')
        ->and(fn () => RecurrenceEditor::splitAt(CalendarEvent::make(id: 'x', title: 'X', start: '2026-10-12'), reInstances([$row])[0]))
        ->toThrow(InvalidArgumentException::class, 'no es una serie');
});

// ── splitAt ────────────────────────────────────────────────────────────

/**
 * La propiedad que importa: la serie original cortada y la nueva desde la ocurrencia,
 * juntas, generan EXACTAMENTE lo que generaba la original.
 */
function reUnion(array $row, int $index, string $zone = 'Europe/Madrid', array $range = ['2026-10-01', '2027-06-30']): array
{
    $series = CalendarEvent::fromArray($row);
    $original = reInstances([$row], $range[0], $range[1], $zone);
    $occurrence = $original[$index];

    $cut = RecurrenceEditor::splitAt($series, $occurrence);

    // La serie nueva empieza donde la ocurrencia, en la representación de la serie.
    $zoned = $occurrence->exactStart !== null && ! is_string($row['start']);
    // La serie nueva se guarda en la zona de la serie, no en la de la vista: es lo que haría
    // Eloquent al guardar (siempre la zona de la aplicación).
    $seriesZone = $zoned ? $row['start']->getTimezone() : null;
    $start = $zoned ? $occurrence->exactStart->setTimezone($seriesZone) : ($occurrence->allDay ? gmdate('Y-m-d', $occurrence->range->start->ts) : gmdate('Y-m-d H:i:s', $occurrence->range->start->ts));
    $end = $zoned ? $occurrence->exactEnd->setTimezone($seriesZone) : ($occurrence->allDay ? gmdate('Y-m-d', $occurrence->range->end->ts) : gmdate('Y-m-d H:i:s', $occurrence->range->end->ts));

    $before = reInstances([[...$row, 'rrule' => $cut['before']]], $range[0], $range[1], $zone);
    $after = reInstances([[...$row, 'id' => 'n', 'start' => $start, 'end' => $end, 'rrule' => $cut['after'], 'exdates' => $row['exdates'] ?? []]], $range[0], $range[1], $zone);

    return [$cut, $original, $before, $after];
}

it('splitAt con COUNT: la original se corta antes y la nueva lleva las que quedaban', function () {
    $row = ['id' => 's', 'title' => 'S', 'start' => '2026-10-12 09:00', 'end' => '2026-10-12 10:00', 'rrule' => 'FREQ=DAILY;COUNT=10'];

    [$cut, $original, $before, $after] = reUnion($row, 3);   // la 4.ª: el 15

    expect($cut)->toBe(['before' => 'FREQ=DAILY;UNTIL=20261015T085959Z', 'after' => 'FREQ=DAILY;COUNT=7'])
        ->and($before)->toHaveCount(3)
        ->and($after)->toHaveCount(7)
        ->and([...reStarts($before), ...reStarts($after)])->toBe(reStarts($original))
        ->and($original)->toHaveCount(10);
});

it('splitAt con UNTIL: la original se corta antes y la nueva conserva el mismo UNTIL', function () {
    $row = ['id' => 's', 'title' => 'S', 'start' => '2026-10-12', 'allDay' => true, 'rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20261031'];

    [$cut, $original, $before, $after] = reUnion($row, 3);   // la 4.ª: miércoles 21

    expect($cut)->toBe(['before' => 'FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20261020T235959Z', 'after' => 'FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20261031'])
        ->and(reStarts($original, 'Y-m-d'))->toBe(['2026-10-12', '2026-10-14', '2026-10-19', '2026-10-21', '2026-10-26', '2026-10-28'])
        ->and(reStarts($before, 'Y-m-d'))->toBe(['2026-10-12', '2026-10-14', '2026-10-19'])
        ->and(reStarts($after, 'Y-m-d'))->toBe(['2026-10-21', '2026-10-26', '2026-10-28'])
        ->and([...reStarts($before), ...reStarts($after)])->toBe(reStarts($original));
});

it('splitAt sin límite: la nueva es la misma regla', function () {
    $row = ['id' => 's', 'title' => 'S', 'start' => '2026-10-12 09:00', 'rrule' => 'FREQ=WEEKLY;INTERVAL=2'];

    [$cut, $original, $before, $after] = reUnion($row, 2, range: ['2026-10-01', '2027-02-01']);

    expect($cut['after'])->toBe('FREQ=WEEKLY;INTERVAL=2')
        ->and($cut['before'])->toStartWith('FREQ=WEEKLY;INTERVAL=2;UNTIL=')
        ->and([...reStarts($before), ...reStarts($after)])->toBe(reStarts($original));
});

it('splitAt en una serie con zona cruza el cambio de hora sin perder ni repetir ocurrencias', function () {
    // Lunes 9:00 en Madrid; el 25-oct cambia la hora. COUNT=6 y se corta en la 4.ª (9-nov).
    $start = CarbonImmutable::parse('2026-10-19 09:00', 'Europe/Madrid');
    $row = ['id' => 's', 'title' => 'S', 'start' => $start, 'end' => $start->addHour(), 'rrule' => 'FREQ=WEEKLY;COUNT=6'];

    [$cut, $original, $before, $after] = reUnion($row, 3);

    expect($cut)->toBe(['before' => 'FREQ=WEEKLY;UNTIL=20261109T075959Z', 'after' => 'FREQ=WEEKLY;COUNT=3'])
        ->and(reStarts($original))->toBe(['2026-10-19 09:00', '2026-10-26 09:00', '2026-11-02 09:00', '2026-11-09 09:00', '2026-11-16 09:00', '2026-11-23 09:00'])
        ->and([...reStarts($before), ...reStarts($after)])->toBe(reStarts($original));
});

it('splitAt con zona y vista en otra zona: el corte es del instante, no de la pared de la vista', function () {
    $start = CarbonImmutable::parse('2026-10-19 09:00', 'Europe/Madrid');
    $row = ['id' => 's', 'title' => 'S', 'start' => $start, 'end' => $start->addHour(), 'rrule' => 'FREQ=WEEKLY;UNTIL=20261201T100000'];

    [$cut, $original, $before, $after] = reUnion($row, 2, zone: 'America/New_York');

    expect($cut['after'])->toBe('FREQ=WEEKLY;UNTIL=20261201T100000')
        ->and([...reStarts($before), ...reStarts($after)])->toBe(reStarts($original))
        ->and($original)->toHaveCount(7);
});

it('un EXDATE no resta del COUNT: la nueva lleva las que quedaban contando las excluidas', function () {
    // 8 ocurrencias (12 a 19), la 5.ª (el 16) excluida. Se corta en la 6.ª (el 17): quedan 3,
    // no 4. Restar las visibles anteriores (4) a las 8 daría 4: el EXDATE no es una ocurrencia menos.
    $row = ['id' => 's', 'title' => 'S', 'start' => '2026-10-12 09:00', 'rrule' => 'FREQ=DAILY;COUNT=8', 'exdates' => ['2026-10-16 09:00:00']];

    [$cut, $original, $before, $after] = reUnion($row, 4);   // la 5.ª visible: el 17 (el 16 no sale)

    expect($cut['after'])->toBe('FREQ=DAILY;COUNT=3')
        ->and(reStarts($original))->toBe(['2026-10-12 09:00', '2026-10-13 09:00', '2026-10-14 09:00', '2026-10-15 09:00', '2026-10-17 09:00', '2026-10-18 09:00', '2026-10-19 09:00'])
        ->and([...reStarts($before), ...reStarts($after)])->toBe(reStarts($original));
});

it('isFirst distingue la primera ocurrencia: ahí «esta y las siguientes» es toda la serie', function () {
    $row = ['id' => 's', 'title' => 'S', 'start' => '2026-10-12 09:00', 'rrule' => 'FREQ=DAILY;COUNT=3'];
    $series = CalendarEvent::fromArray($row);
    $all = reInstances([$row]);

    expect(RecurrenceEditor::isFirst($series, $all[0]))->toBeTrue()
        ->and(RecurrenceEditor::isFirst($series, $all[1]))->toBeFalse();

    $zoned = ['id' => 's', 'title' => 'S', 'start' => CarbonImmutable::parse('2026-10-19 09:00', 'Europe/Madrid'), 'rrule' => 'FREQ=WEEKLY;COUNT=3'];
    $zonedAll = reInstances([$zoned], zone: 'America/New_York');

    expect(RecurrenceEditor::isFirst(CalendarEvent::fromArray($zoned), $zonedAll[0]))->toBeTrue()
        ->and(RecurrenceEditor::isFirst(CalendarEvent::fromArray($zoned), $zonedAll[2]))->toBeFalse();
});

// ── shift ──────────────────────────────────────────────────────────────

it('shift: cada forma de fecha sigue siendo la suya', function () {
    $delta = 86_400 + 2 * 3600;   // un día y dos horas

    expect(RecurrenceEditor::shift(CalendarEvent::make(id: 's', title: 'S', start: '2026-10-12 09:00', end: '2026-10-12 10:30', rrule: 'FREQ=DAILY'), $delta))
        ->toBe(['start' => '2026-10-13 11:00:00', 'end' => '2026-10-13 12:30:00'])
        ->and(RecurrenceEditor::shift(CalendarEvent::make(id: 's', title: 'S', start: '2026-10-12', end: '2026-10-14', allDay: true, rrule: 'FREQ=WEEKLY'), 3 * 86_400))
        ->toBe(['start' => '2026-10-15', 'end' => '2026-10-17'])
        // Sin fin sigue sin fin.
        ->and(RecurrenceEditor::shift(CalendarEvent::make(id: 's', title: 'S', start: '2026-10-12 09:00', rrule: 'FREQ=DAILY'), 3600))
        ->toBe(['start' => '2026-10-12 10:00:00', 'end' => null]);
});

it('shift con zona suma en pared de la serie: «las 9 de Madrid» movido un día sigue a las 9 tras el cambio de hora', function () {
    $start = CarbonImmutable::parse('2026-10-24 09:00', 'Europe/Madrid');   // el día antes del cambio
    $moved = RecurrenceEditor::shift(CalendarEvent::make(id: 's', title: 'S', start: $start, end: $start->addHour(), rrule: 'FREQ=WEEKLY'), 86_400);

    expect($moved['start']->format('c'))->toBe('2026-10-25T09:00:00+01:00')
        ->and($moved['end']->format('c'))->toBe('2026-10-25T10:00:00+01:00')
        ->and($moved['start']->getTimezone()->getName())->toBe('Europe/Madrid');

    // Un string con offset conserva su offset.
    $text = RecurrenceEditor::shift(CalendarEvent::make(id: 's', title: 'S', start: '2026-10-12T09:00:00+02:00', rrule: 'FREQ=DAILY'), 86_400);

    expect($text['start'])->toBe('2026-10-13T09:00:00+02:00');
});

it('shift aplicado al mover una ocurrencia con all: toda la serie se desplaza lo mismo', function () {
    $row = ['id' => 's', 'title' => 'S', 'start' => '2026-10-12 09:00', 'end' => '2026-10-12 10:00', 'rrule' => 'FREQ=WEEKLY;COUNT=3'];
    $occ = reInstances([$row])[1];   // lunes 19

    // Se arrastra al miércoles 21 a las 11:00.
    $mutation = MutationBuilder::fromSettings(ixSettings())->move($occ, Marker::parse('2026-10-21 11:00'), false)->withScope('all');
    $moved = RecurrenceEditor::shift(CalendarEvent::fromArray($row), $mutation->deltaSeconds);

    expect($mutation->deltaSeconds)->toBe(2 * 86_400 + 2 * 3600)
        ->and($moved)->toBe(['start' => '2026-10-14 11:00:00', 'end' => '2026-10-14 12:00:00'])
        ->and(reStarts(reInstances([[...$row, ...$moved, 'rrule' => 'FREQ=WEEKLY;COUNT=3']])))->toBe(['2026-10-14 11:00', '2026-10-21 11:00', '2026-10-28 11:00']);
});

// ── resize ─────────────────────────────────────────────────────────────

it('resize con all: todas pasan a durar lo que dura la redimensionada', function () {
    $row = ['id' => 's', 'title' => 'S', 'start' => '2026-10-12 09:00', 'end' => '2026-10-12 10:00', 'rrule' => 'FREQ=WEEKLY;COUNT=3'];
    $occ = reInstances([$row])[1];
    $builder = MutationBuilder::fromSettings(ixSettings());
    $series = CalendarEvent::fromArray($row);

    // El fin de la del 19, a las 11:30.
    $end = $builder->resize($occ, Marker::parse('2026-10-19 11:30'));
    expect(RecurrenceEditor::resize($series, $end))->toBe(['start' => '2026-10-12 09:00', 'end' => '2026-10-12 11:30:00']);

    // El inicio, a las 08:00: el inicio de todas se adelanta una hora y el fin de cada una se queda a las 10:00.
    $start = $builder->resize($occ, Marker::parse('2026-10-19 08:00'), fromStart: true);
    expect(RecurrenceEditor::resize($series, $start))->toBe(['start' => '2026-10-12 08:00:00', 'end' => '2026-10-12 10:00:00']);
});

it('resize con all en una serie con zona mide la duración en tiempo real', function () {
    $start = CarbonImmutable::parse('2026-10-19 09:00', 'Europe/Madrid');
    $row = ['id' => 's', 'title' => 'S', 'start' => $start, 'end' => $start->addHour(), 'rrule' => 'FREQ=WEEKLY;COUNT=3'];
    $occ = reInstances([$row])[0];
    $mutation = MutationBuilder::fromSettings(ixSettings())->resize($occ, Marker::parse('2026-10-19 11:00'));

    $new = RecurrenceEditor::resize(CalendarEvent::fromArray($row), $mutation);

    expect($new['start'])->toBe($start)
        ->and($new['end']->format('c'))->toBe('2026-10-19T11:00:00+02:00');
});

it('resize con all en un día completo mide la duración en días', function () {
    $row = ['id' => 's', 'title' => 'S', 'start' => '2026-10-12', 'end' => '2026-10-13', 'allDay' => true, 'rrule' => 'FREQ=WEEKLY;COUNT=3'];
    $occ = reInstances([$row])[1];
    $mutation = MutationBuilder::fromSettings(ixSettings())->resize($occ, Marker::parse('2026-10-22'));

    expect(RecurrenceEditor::resize(CalendarEvent::fromArray($row), $mutation))->toBe(['start' => '2026-10-12', 'end' => '2026-10-15']);
});

// ── override ───────────────────────────────────────────────────────────

it('override desde una mutación: una serie flotante, lista para guardar', function () {
    $row = ['id' => 's', 'title' => 'Daily', 'start' => '2026-10-12 09:00', 'end' => '2026-10-12 10:00', 'rrule' => 'FREQ=DAILY;COUNT=3', 'color' => 'info', 'resourceIds' => [4]];
    $occ = reInstances([$row])[1];   // el 13
    $mutation = MutationBuilder::fromSettings(ixSettings())->move($occ, Marker::parse('2026-10-14 11:00'), false)->withScope('this');

    $override = RecurrenceEditor::override(CalendarEvent::fromArray($row), $occ, $mutation);

    expect($override)->toBe([
        'recurrenceOf' => 's',
        'recurrenceId' => '2026-10-13 09:00:00',
        'title' => 'Daily',
        'start' => '2026-10-14 11:00:00',
        'end' => '2026-10-14 12:00:00',
        'allDay' => false,
        'resourceIds' => [4],
        'color' => 'info',
    ]);

    // Y guardado como un evento más (con id propio), sustituye a la generada.
    $starts = reStarts(reInstances([$row, ['id' => 'o1', ...$override]]));

    expect($starts)->toBe(['2026-10-12 09:00', '2026-10-14 09:00', '2026-10-14 11:00'])
        ->not->toContain('2026-10-13 09:00');
});

it('override desde una mutación: la ocurrencia de un día completo se identifica por su día', function () {
    $row = ['id' => 's', 'title' => 'S', 'start' => '2020-12-01', 'allDay' => true, 'rrule' => 'FREQ=WEEKLY'];
    $occ = reInstances([$row], '2020-12-01', '2021-01-01')[2];   // 15-dic
    $mutation = MutationBuilder::fromSettings(ixSettings())->move($occ, Marker::parse('2020-12-17'), true)->withScope('this');

    $override = RecurrenceEditor::override(CalendarEvent::fromArray($row), $occ, $mutation);

    expect($override['recurrenceId'])->toBe('2020-12-15')
        ->and($override['start'])->toBe('2020-12-17')
        // El evento no tenía fin y sigue sin tenerlo: moverlo no se lo inventa.
        ->and($override['end'])->toBeNull()
        ->and($override['allDay'])->toBeTrue();

    expect(reStarts(reInstances([$row, ['id' => 'o1', ...$override]], '2020-12-01', '2021-01-01'), 'Y-m-d'))
        ->toBe(['2020-12-01', '2020-12-08', '2020-12-17', '2020-12-22', '2020-12-29']);
});

it('override en una serie con zona: el recurrenceId y las fechas son instantes en la zona de la serie', function () {
    $start = CarbonImmutable::parse('2026-10-19 09:00', 'Europe/Madrid');
    $row = ['id' => 's', 'title' => 'S', 'start' => $start, 'end' => $start->addHour(), 'rrule' => 'FREQ=WEEKLY;COUNT=3'];
    // Vista en Nueva York: la del 26 se ve a las 04:00.
    $occ = reInstances([$row], zone: 'America/New_York')[1];
    $settings = ixSettings(['timezone' => 'America/New_York']);
    $mutation = MutationBuilder::fromSettings($settings)->move($occ, Marker::parse('2026-10-27 05:00'), false)->withScope('this');

    $override = RecurrenceEditor::override(CalendarEvent::fromArray($row), $occ, $mutation);

    expect($override['recurrenceId'])->toBeInstanceOf(DateTimeInterface::class)
        ->and($override['recurrenceId']->format('c'))->toBe('2026-10-26T09:00:00+01:00')
        ->and($override['start']->format('c'))->toBe('2026-10-27T10:00:00+01:00')
        ->and($override['end']->format('c'))->toBe('2026-10-27T11:00:00+01:00');

    $starts = reStarts(reInstances([$row, ['id' => 'o1', ...$override]], zone: 'America/New_York'));

    expect($starts)->toBe(['2026-10-19 03:00', '2026-10-27 05:00', '2026-11-02 03:00']);
});

it('override desde los datos del formulario: título, fechas y color nuevos', function () {
    $row = ['id' => 's', 'title' => 'Daily', 'start' => '2026-10-12 09:00', 'end' => '2026-10-12 10:00', 'rrule' => 'FREQ=DAILY;COUNT=3'];
    $occ = reInstances([$row])[2];   // el 14

    $override = RecurrenceEditor::override(CalendarEvent::fromArray($row), $occ, [
        'mode' => 'edit', 'id' => 's', 'title' => 'Daily (tarde)', 'allDay' => false,
        'start' => '2026-10-14 16:00:00', 'end' => '2026-10-14 16:30:00',
        'resourceId' => null, 'resourceIds' => [], 'color' => 'success',
        'startAt' => CarbonImmutable::parse('2026-10-14 16:00', 'Europe/Madrid'), 'endAt' => CarbonImmutable::parse('2026-10-14 16:30', 'Europe/Madrid'),
        'scope' => 'this',
    ]);

    expect($override)->toBe([
        'recurrenceOf' => 's',
        'recurrenceId' => '2026-10-14 09:00:00',
        'title' => 'Daily (tarde)',
        'start' => '2026-10-14 16:00:00',
        'end' => '2026-10-14 16:30:00',
        'allDay' => false,
        'resourceIds' => [],
        'color' => 'success',
    ]);
});

it('override toma de la ocurrencia lo que el cambio no trae', function () {
    $row = ['id' => 's', 'title' => 'Daily', 'start' => '2026-10-12 09:00', 'end' => '2026-10-12 10:00', 'rrule' => 'FREQ=DAILY;COUNT=3', 'color' => 'info'];
    $occ = reInstances([$row])[0];

    $override = RecurrenceEditor::override(CalendarEvent::fromArray($row), $occ, ['title' => 'Solo el título']);

    expect($override['title'])->toBe('Solo el título')
        ->and($override['start'])->toBe('2026-10-12 09:00:00')
        ->and($override['end'])->toBe('2026-10-12 10:00:00')
        ->and($override['color'])->toBe('info');
});
