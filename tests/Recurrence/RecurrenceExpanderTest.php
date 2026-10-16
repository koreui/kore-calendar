<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventCollector;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Events\Sources\ArraySource;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;

/** Las ocurrencias de unos eventos en un rango, como "fecha hora" de pared de la vista. */
function rcCollect(array $events, string $from, string $to, string $zone = 'UTC', ?int $max = null): array
{
    $clock = new WallClock($zone);
    $collector = new EventCollector($clock, new EventNormalizer($clock), $max);
    $instances = $collector->collect([new ArraySource($events)], new DateRange(Marker::parse($from), Marker::parse($to)));
    usort($instances, fn ($a, $b) => $a->range->start->ts <=> $b->range->start->ts);

    return $instances;
}

function rcStarts(array $instances, string $format = 'Y-m-d H:i'): array
{
    return array_map(fn ($i) => gmdate($format, $i->range->start->ts), $instances);
}

it('expande una regla diaria con COUNT', function () {
    $i = rcCollect([['id' => 's', 'title' => 'Daily', 'start' => '2026-10-12 09:30', 'end' => '2026-10-12 09:45', 'rrule' => 'FREQ=DAILY;COUNT=3']], '2026-10-01', '2026-11-01');

    expect(rcStarts($i))->toBe(['2026-10-12 09:30', '2026-10-13 09:30', '2026-10-14 09:30'])
        ->and($i[0]->range->seconds())->toBe(900);
});

it('EXDATE quita solo esa ocurrencia (FullCalendar datelib/rrule.ts)', function () {
    // Semanal desde el martes 1-dic-2020 sin el 8-dic: 1, 15, 22, 29 dic y 5 ene.
    $i = rcCollect([['id' => 's', 'title' => 'S', 'start' => '2020-12-01', 'allDay' => true, 'rrule' => 'FREQ=WEEKLY', 'exdates' => ['2020-12-08']]], '2020-11-30', '2021-01-11');

    expect(rcStarts($i, 'Y-m-d'))->toBe(['2020-12-01', '2020-12-15', '2020-12-22', '2020-12-29', '2021-01-05']);
});

it('una regla flotante sigue a la misma hora de pared tras el cambio de hora', function () {
    // Madrid cambia el 25-oct-2026. «Cada día a las 9» sigue a las 9.
    $i = rcCollect([['id' => 's', 'title' => 'S', 'start' => '2026-10-24 09:00', 'end' => '2026-10-24 10:00', 'rrule' => 'FREQ=DAILY;COUNT=3']], '2026-10-20', '2026-10-30', 'Europe/Madrid');

    expect(rcStarts($i))->toBe(['2026-10-24 09:00', '2026-10-25 09:00', '2026-10-26 09:00'])
        ->and($i[1]->occurrenceStart->toIso8601String())->toBe('2026-10-25T09:00:00+01:00');
});

it('una regla con zona se repite en SU zona aunque la vista esté en otra', function () {
    // «Lunes 9:00 en Madrid» visto en Nueva York. Madrid cambia el 25-oct y NY el
    // 1-nov: durante esa semana la diferencia es de 5 h, no de 6.
    $start = CarbonImmutable::parse('2026-10-19 09:00', 'Europe/Madrid');
    $i = rcCollect([['id' => 's', 'title' => 'S', 'start' => $start, 'end' => $start->addHour(), 'rrule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=3']], '2026-10-01', '2026-11-30', 'America/New_York');

    expect(rcStarts($i))->toBe(['2026-10-19 03:00', '2026-10-26 04:00', '2026-11-02 03:00']);
});

it('una ocurrencia que empezó antes de la vista y entra en ella también aparece (#4617)', function () {
    // Sábados de dos días; la vista empieza el domingo 2-jun-2019.
    $i = rcCollect([['id' => 's', 'title' => 'S', 'start' => '2019-05-04', 'end' => '2019-05-06', 'allDay' => true, 'rrule' => 'FREQ=WEEKLY;BYDAY=SA']], '2019-06-02', '2019-06-09');

    expect(rcStarts($i, 'Y-m-d'))->toBe(['2019-06-01', '2019-06-08']);
});

it('no pierde ocurrencias con un inicio en UTC y una vista en Asia/Manila (#5993)', function () {
    // 16:00Z = 00:00 en Manila: una regla con zona que en la vista cae a medianoche.
    $i = rcCollect([['id' => 's', 'title' => 'S', 'start' => '2026-10-11T16:00:00Z', 'end' => '2026-10-11T17:00:00Z', 'rrule' => 'FREQ=DAILY;COUNT=3']], '2026-10-12', '2026-10-15', 'Asia/Manila');

    expect(rcStarts($i))->toBe(['2026-10-12 00:00', '2026-10-13 00:00', '2026-10-14 00:00']);
});

it('un EXDATE con Z excluye en una serie con zona (#5726)', function () {
    $i = rcCollect([[
        'id' => 's', 'title' => 'S', 'start' => '2026-10-12T08:00:00Z', 'end' => '2026-10-12T09:00:00Z',
        'rrule' => 'FREQ=DAILY;COUNT=3', 'exdates' => ['2026-10-13T08:00:00Z'],
    ]], '2026-10-01', '2026-11-01', 'Europe/Madrid');

    expect(rcStarts($i))->toBe(['2026-10-12 10:00', '2026-10-14 10:00']);
});

it('la ocurrencia modificada sustituye a la generada, no se suma (#6451)', function () {
    $i = rcCollect([
        ['id' => 's', 'title' => 'Daily', 'start' => '2026-10-12 09:30', 'end' => '2026-10-12 09:45', 'rrule' => 'FREQ=DAILY;COUNT=3'],
        // La del martes se movió a las 11 y cambió de título.
        ['id' => 'o1', 'title' => 'Daily (tarde)', 'start' => '2026-10-13 11:00', 'end' => '2026-10-13 11:15', 'recurrenceOf' => 's', 'recurrenceId' => '2026-10-13 09:30'],
    ], '2026-10-01', '2026-11-01');

    expect(rcStarts($i))->toBe(['2026-10-12 09:30', '2026-10-13 11:00', '2026-10-14 09:30'])
        ->and($i[1]->event->title)->toBe('Daily (tarde)')
        ->and($i[1]->seriesId)->toBe('s')
        ->and(gmdate('Y-m-d H:i', $i[1]->recurrenceId->ts))->toBe('2026-10-13 09:30')
        // Mismo instanceId que tendría la generada: identifica la ocurrencia, no su posición.
        ->and($i[1]->instanceId)->toBe('s:'.Marker::parse('2026-10-13 09:30')->ts);
});

it('cada ocurrencia sabe de qué serie viene y cuál es', function () {
    $i = rcCollect([['id' => 7, 'title' => 'S', 'start' => '2026-10-12 09:00', 'rrule' => 'FREQ=DAILY;COUNT=2']], '2026-10-01', '2026-11-01');

    expect($i[0]->isOccurrence())->toBeTrue()
        ->and($i[0]->seriesId)->toBe(7)
        ->and(array_unique(array_map(fn ($x) => $x->instanceId, $i)))->toHaveCount(2)
        // Sin fin: la duración por defecto, como cualquier evento.
        ->and($i[0]->range->seconds())->toBe(3600);
});

it('UNTIL corta la serie', function () {
    $i = rcCollect([['id' => 's', 'title' => 'S', 'start' => '2026-10-12', 'allDay' => true, 'rrule' => 'FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20261021']], '2026-10-01', '2026-12-01');

    expect(rcStarts($i, 'Y-m-d'))->toBe(['2026-10-12', '2026-10-14', '2026-10-19', '2026-10-21']);
});

it('un tope de ocurrencias por serie y vista', function () {
    $i = rcCollect([['id' => 's', 'title' => 'S', 'start' => '2026-10-01 00:00', 'end' => '2026-10-01 00:01', 'rrule' => 'FREQ=MINUTELY']], '2026-10-01', '2026-10-08', 'UTC', 50);

    expect($i)->toHaveCount(50);
});

it('solo genera las ocurrencias del rango', function () {
    $i = rcCollect([['id' => 's', 'title' => 'S', 'start' => '2020-01-01', 'allDay' => true, 'rrule' => 'FREQ=DAILY']], '2026-10-12', '2026-10-19');

    expect(rcStarts($i, 'Y-m-d'))->toHaveCount(7)->and(rcStarts($i, 'Y-m-d')[0])->toBe('2026-10-12');
});

it('rechaza una regla mal escrita, y una con DTSTART', function () {
    expect(fn () => rcCollect([['id' => 's', 'title' => 'S', 'start' => '2026-10-12', 'rrule' => 'FREQ=NUNCA']], '2026-10-01', '2026-11-01'))
        ->toThrow(InvalidArgumentException::class, 'rrule')
        ->and(fn () => CalendarEvent::make(id: 's', title: 'S', start: '2026-10-12', rrule: "DTSTART:20261012\nFREQ=DAILY"))
        ->toThrow(InvalidArgumentException::class, 'DTSTART');
});

it('una ocurrencia modificada necesita las dos claves', function () {
    expect(fn () => CalendarEvent::make(id: 'o', title: 'O', start: '2026-10-12', recurrenceOf: 's'))->toThrow(InvalidArgumentException::class, 'recurrenceId')
        ->and(fn () => CalendarEvent::make(id: 'o', title: 'O', start: '2026-10-12', exdates: ['2026-10-13']))->toThrow(InvalidArgumentException::class, 'exdates');
});

it('UNTIL con hora local en una serie con zona se lee en esa zona', function () {
    // Hasta las 10:00 de Madrid del 14 (08:00Z): entra la del 14 a las 09:00 de Madrid.
    $start = CarbonImmutable::parse('2026-10-12 09:00', 'Europe/Madrid');
    $i = rcCollect([['id' => 's', 'title' => 'S', 'start' => $start, 'rrule' => 'FREQ=DAILY;UNTIL=20261014T100000']], '2026-10-01', '2026-11-01', 'Europe/Madrid');

    expect(rcStarts($i))->toBe(['2026-10-12 09:00', '2026-10-13 09:00', '2026-10-14 09:00']);
});
