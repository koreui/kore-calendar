<?php

use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\ICal\ICalReader;

require_once __DIR__.'/helpers.php';

/*
| La propiedad que importa: read(write($events)) devuelve eventos equivalentes, y al expandirlos
| con EventCollector salen las MISMAS ocurrencias que con los originales. Una serie con zona que
| vuelve como flotante parece igual hasta el primer cambio de hora.
*/

function icalTrip(array $events, array $writeOptions = []): array
{
    $reader = new ICalReader;
    $back = $reader->read(icalWrite($events, $writeOptions), ['uidDomain' => 'ej.test', 'strict' => true]);

    expect($reader->errors())->toBe([])->and($reader->warnings())->toBe([]);

    return $back;
}

/** @param  list<CalendarEvent>  $events */
function icalAssertSame(array $events, string $from, string $to, array $zones = ['Europe/Madrid', 'America/New_York', 'UTC', 'Asia/Tokyo']): void
{
    $back = icalTrip($events);

    expect(array_map(icalShape(...), $back))->toBe(array_map(icalShape(...), $events));

    foreach ($zones as $zone) {
        $expected = icalOccurrences($events, $from, $to, $zone);

        // Que no sea una comparación de dos listas vacías.
        expect($expected)->not->toBeEmpty()
            ->and(icalOccurrences($back, $from, $to, $zone))->toBe($expected, "vista en {$zone}");
    }
}

function icalTz2(string $wall, string $zone = 'Europe/Madrid'): DateTimeImmutable
{
    return new DateTimeImmutable($wall, new DateTimeZone($zone));
}

// ── Una representación cada vez ────────────────────────────────────────

it('día completo', function () {
    icalAssertSame([
        CalendarEvent::make(id: 'congreso', title: 'Congreso', start: '2026-10-14', end: '2026-10-17'),
        CalendarEvent::make(id: 'suelto', title: 'Un día', start: '2026-10-20'),
        CalendarEvent::make(id: 'explicito', title: 'Explícito', start: '2026-10-22', end: '2026-10-23', allDay: true),
    ], '2026-10-01', '2026-11-01');
});

it('con zona: la fecha vuelve en su zona, con el mismo instante', function () {
    $events = [
        CalendarEvent::make(id: 'madrid', title: 'Madrid', start: icalTz2('2026-10-16 09:00'), end: icalTz2('2026-10-16 10:30')),
        CalendarEvent::make(id: 'ny', title: 'Nueva York', start: icalTz2('2026-11-02 09:00', 'America/New_York'), end: icalTz2('2026-11-02 10:00', 'America/New_York')),
        CalendarEvent::make(id: 'sin-fin', title: 'Sin fin', start: icalTz2('2026-10-17 18:00', 'Asia/Tokyo')),
    ];

    icalAssertSame($events, '2026-10-01', '2026-12-01');

    $back = icalTrip($events);
    expect($back[0]->start->getTimezone()->getName())->toBe('Europe/Madrid')
        ->and($back[1]->start->getTimezone()->getName())->toBe('America/New_York')
        ->and($back[2]->start->getTimezone()->getName())->toBe('Asia/Tokyo');
});

it('instante UTC', function () {
    icalAssertSame([
        CalendarEvent::make(id: 'utc', title: 'UTC', start: icalTz2('2026-10-20 14:00', 'UTC'), end: icalTz2('2026-10-20 15:00', 'UTC')),
        CalendarEvent::make(id: 'z', title: 'Con Z', start: icalTz2('2026-10-21 14:00', 'UTC')),
    ], '2026-10-01', '2026-12-01');
});

it('flotante: sigue siendo hora de pared, sin zona', function () {
    $events = [
        CalendarEvent::make(id: 'flot', title: 'Flotante', start: '2026-10-16 10:00', end: '2026-10-16 11:30:15'),
        CalendarEvent::make(id: 'flot2', title: 'Sin fin', start: '2026-10-17 08:00:00'),
    ];

    icalAssertSame($events, '2026-10-01', '2026-12-01');

    expect(icalTrip($events)[0]->start)->toBeString()->toBe('2026-10-16 10:00:00');
});

it('un string con offset vuelve como el mismo instante (en UTC)', function () {
    $events = [CalendarEvent::make(id: 'off', title: 'Offset', start: '2026-10-16T09:00:00+02:00', end: '2026-10-16T10:00:00+02:00')];
    $back = icalTrip($events);

    expect($back[0]->start->getTimestamp())->toBe((new DateTimeImmutable('2026-10-16T09:00:00+02:00'))->getTimestamp())
        ->and(icalOccurrences($back, '2026-10-01', '2026-11-01'))->toBe(icalOccurrences($events, '2026-10-01', '2026-11-01'));
});

// ── Series ─────────────────────────────────────────────────────────────

it('serie con zona, EXDATE y una ocurrencia modificada, cruzando el cambio de hora', function () {
    icalAssertSame([
        CalendarEvent::make(
            id: 'standup', title: 'Standup', start: icalTz2('2026-10-12 09:00'), end: icalTz2('2026-10-12 09:30'),
            rrule: 'FREQ=WEEKLY;BYDAY=MO,WE;UNTIL=20261231T225959Z', exdates: [icalTz2('2026-10-14 09:00'), icalTz2('2026-11-04 09:00')],
            resourceIds: ['ambar'], color: 'chart-3', url: 'https://example.com/standup',
        ),
        CalendarEvent::make(
            id: 'standup-mv', title: 'Standup (movido)', start: icalTz2('2026-10-19 10:00'), end: icalTz2('2026-10-19 10:30'),
            recurrenceOf: 'standup', recurrenceId: icalTz2('2026-10-19 09:00'),
        ),
    ], '2026-10-01', '2027-01-15');
});

it('la serie con zona sigue a las 9 de Madrid vista desde Nueva York, con los dos cambios de hora', function () {
    $events = [CalendarEvent::make(id: 's', title: 'S', start: icalTz2('2026-10-19 09:00'), end: icalTz2('2026-10-19 10:00'), rrule: 'FREQ=WEEKLY;BYDAY=MO;COUNT=4')];
    $back = icalTrip($events);

    // 3, 4, 3 y 3 de la mañana en Nueva York: Madrid cambia el 25-oct y Nueva York el 1-nov.
    expect(array_column(icalOccurrences($back, '2026-10-01', '2026-12-01', 'America/New_York'), 0))
        ->toBe(['2026-10-19 03:00:00', '2026-10-26 04:00:00', '2026-11-02 03:00:00', '2026-11-09 03:00:00']);
});

it('serie de día completo con EXDATE y una excepción cuyo RECURRENCE-ID es solo una fecha', function () {
    icalAssertSame([
        CalendarEvent::make(id: 'guardia', title: 'Guardia', start: '2026-10-05', end: '2026-10-06', rrule: 'FREQ=WEEKLY;COUNT=8', exdates: ['2026-10-19', '2026-11-09']),
        CalendarEvent::make(id: 'guardia-mv', title: 'Guardia (cambio)', start: '2026-10-28', end: '2026-10-29', recurrenceOf: 'guardia', recurrenceId: '2026-10-26'),
    ], '2026-10-01', '2026-12-31');
});

it('serie flotante: «cada día a las 9» sigue a las 9 tras el cambio de hora', function () {
    $events = [
        CalendarEvent::make(id: 'diario', title: 'Diario', start: '2026-10-23 09:00', end: '2026-10-23 09:15', rrule: 'FREQ=DAILY;COUNT=5', exdates: ['2026-10-25 09:00:00']),
        CalendarEvent::make(id: 'diario-mv', title: 'Diario (tarde)', start: '2026-10-26 17:00', end: '2026-10-26 17:15', recurrenceOf: 'diario', recurrenceId: '2026-10-26 09:00:00'),
    ];

    icalAssertSame($events, '2026-10-20', '2026-11-05');

    expect(array_column(icalOccurrences(icalTrip($events), '2026-10-20', '2026-11-05'), 0))
        ->toBe(['2026-10-23 09:00:00', '2026-10-24 09:00:00', '2026-10-26 17:00:00', '2026-10-27 09:00:00']);
});

it('serie en UTC', function () {
    icalAssertSame([
        CalendarEvent::make(id: 'utc', title: 'UTC', start: icalTz2('2026-10-12 07:00', 'UTC'), end: icalTz2('2026-10-12 08:00', 'UTC'), rrule: 'FREQ=DAILY;COUNT=20', exdates: [icalTz2('2026-10-14 07:00', 'UTC')]),
    ], '2026-10-01', '2026-11-30');
});

it('serie con offset fijo: conserva su hora de pared aunque la hora UTC cambie de día', function () {
    // Lunes 01:00+02:00 es domingo 23:00 UTC: en UTC, BYDAY=MO no la reproduciría.
    $events = [CalendarEvent::make(id: 'off', title: 'Offset', start: '2026-10-12T01:00:00+02:00', end: '2026-10-12T02:00:00+02:00', rrule: 'FREQ=WEEKLY;BYDAY=MO;COUNT=4')];
    $back = icalTrip($events);

    expect(icalOccurrences($back, '2026-10-01', '2026-12-01', 'UTC'))->toBe(icalOccurrences($events, '2026-10-01', '2026-12-01', 'UTC'))
        ->and(array_column(icalOccurrences($back, '2026-10-01', '2026-12-01', 'UTC'), 0))->toBe(['2026-10-11 23:00:00', '2026-10-18 23:00:00', '2026-10-25 23:00:00', '2026-11-01 23:00:00']);
});

it('UNTIL como solo fecha (lo natural de escribir) vuelve equivalente: la misma última ocurrencia', function () {
    $events = [CalendarEvent::make(id: 's', title: 'S', start: icalTz2('2026-12-28 09:00'), rrule: 'FREQ=DAILY;UNTIL=20261231')];

    // Una serie hasta el 31, inclusive: 28, 29, 30 y 31 a las 9.
    expect(array_column(icalOccurrences(icalTrip($events), '2026-12-01', '2027-02-01'), 0))->toBe(array_column(icalOccurrences($events, '2026-12-01', '2027-02-01'), 0))
        ->and(icalOccurrences($events, '2026-12-01', '2027-02-01'))->toHaveCount(4);
});

it('una serie con EXDATE de solo fecha sobre ocurrencias con hora excluye las mismas', function () {
    $events = [
        CalendarEvent::make(id: 'z', title: 'Z', start: icalTz2('2026-10-12 09:00'), rrule: 'FREQ=DAILY;COUNT=6', exdates: ['2026-10-14']),
        CalendarEvent::make(id: 'f', title: 'F', start: '2026-10-12 09:00', rrule: 'FREQ=DAILY;COUNT=6', exdates: ['2026-10-15']),
    ];
    $back = icalTrip($events);

    expect(icalOccurrences($back, '2026-10-01', '2026-11-01'))->toBe(icalOccurrences($events, '2026-10-01', '2026-11-01'))
        ->and(icalOccurrences($events, '2026-10-01', '2026-11-01'))->toHaveCount(10);
});

// ── Resto de campos ────────────────────────────────────────────────────

it('recursos, color y URL se conservan, con ids raros', function () {
    icalAssertSame([
        CalendarEvent::make(id: 'r', title: 'R', start: '2026-10-16 10:00', end: '2026-10-16 11:00', resourceIds: ['ambar', 7, 'sala, grande', 'a;b', 'con\\barra'], color: 'oklch(0.7 0.1 200)', url: 'https://example.com/e?id=1,2&x=a;b'),
        CalendarEvent::make(id: 'rgb', title: 'RGB', start: '2026-10-17', color: 'rgb(255, 0, 0)'),
    ], '2026-10-01', '2026-11-01');
});

it('el texto con comas, punto y coma, barras, saltos de línea, tildes y emoji sobrevive intacto, incluido a través del plegado', function () {
    $title = 'Revisión; "Ámbar", C:\\tmp 🎉 日本語';
    $description = "Línea 1\nLínea 2 con; punto y coma, comas y \\barra\\ — ".str_repeat('texto largo con ñ y 🚀 para plegar. ', 12);
    $events = [CalendarEvent::make(id: 'txt', title: $title, start: '2026-10-16', meta: ['description' => $description, 'location' => 'Sala A, planta 2', 'status' => 'TENTATIVE', 'categories' => ['Trabajo', 'Equipo, A'], 'sequence' => 2, 'x' => ['X-FOO' => "a\nb"]])];

    $back = icalTrip($events);

    expect($back[0]->title)->toBe($title)
        ->and($back[0]->meta)->toBe([
            'description' => $description, 'location' => 'Sala A, planta 2', 'status' => 'TENTATIVE',
            'categories' => ['Trabajo', 'Equipo, A'], 'sequence' => 2, 'x' => ['X-FOO' => "a\nb"],
        ]);
});

it('escribir lo leído da el mismo archivo: la ida y vuelta es idempotente', function () {
    $events = [
        CalendarEvent::make(id: 'standup', title: 'Standup', start: icalTz2('2026-10-12 09:00'), end: icalTz2('2026-10-12 09:30'), rrule: 'FREQ=WEEKLY;COUNT=6', exdates: [icalTz2('2026-10-19 09:00')], resourceIds: ['a', 'b'], color: 'chart-1'),
        CalendarEvent::make(id: 'mv', title: 'Movido', start: icalTz2('2026-10-27 10:00'), recurrenceOf: 'standup', recurrenceId: icalTz2('2026-10-26 09:00')),
        CalendarEvent::make(id: 'dia', title: 'Día', start: '2026-10-30', end: '2026-11-02'),
        CalendarEvent::make(id: 'utc', title: 'UTC', start: icalTz2('2026-10-20 14:00', 'UTC')),
        CalendarEvent::make(id: 'flot', title: 'Flotante', start: '2026-10-21 10:00', rrule: 'FREQ=DAILY;COUNT=3'),
    ];

    $first = icalWrite($events);
    $second = icalWrite((new ICalReader)->read($first, ['uidDomain' => 'ej.test']));

    expect($second)->toBe($first);
});

it('un id numérico vuelve como string, y la excepción sigue apuntando a su serie', function () {
    $events = [
        CalendarEvent::make(id: 7, title: 'Serie', start: icalTz2('2026-10-12 09:00'), rrule: 'FREQ=WEEKLY;COUNT=3'),
        CalendarEvent::make(id: 'x', title: 'Mov', start: icalTz2('2026-10-20 09:00'), recurrenceOf: 7, recurrenceId: icalTz2('2026-10-19 09:00')),
    ];

    $back = icalTrip($events);

    // Un id numérico vuelve como string; la excepción conserva la referencia a su serie.
    expect($back[0]->id)->toBe('7')->and($back[1]->recurrenceOf)->toBe('7')
        ->and(array_column(icalOccurrences($back, '2026-10-01', '2026-11-01'), 3))->toBe(array_column(icalOccurrences($events, '2026-10-01', '2026-11-01'), 3));
});

it('lo que iCalendar no puede llevar se pierde sin romper nada', function () {
    $events = [CalendarEvent::make(
        id: 'a', title: 'A', start: '2026-10-16 10:00', groupId: 'g1', display: 'background', editable: false, startEditable: true,
        durationEditable: false, overlap: false, meta: ['propio' => 'dato de la aplicación'],
    )];

    $back = icalTrip($events)[0];

    expect($back->groupId)->toBeNull()->and($back->display)->toBe('auto')->and($back->editable)->toBeNull()->and($back->overlap)->toBeNull()
        ->and($back->meta)->not->toHaveKey('propio');
});

it('la hora repetida del otoño: la segunda vuelta no se pierde (se escribe como instante) y la primera conserva su zona', function () {
    // Madrid, 25-oct-2026: las 02:30 existen dos veces (+02:00 y +01:00). Un TZID no puede distinguirlas.
    $first = new DateTimeImmutable('2026-10-25T02:30:00+02:00');
    $second = new DateTimeImmutable('2026-10-25T02:30:00+01:00');
    $events = [
        CalendarEvent::make(id: 'primera', title: 'Primera', start: $first->setTimezone(new DateTimeZone('Europe/Madrid')), end: $first->modify('+30 minutes')->setTimezone(new DateTimeZone('Europe/Madrid'))),
        CalendarEvent::make(id: 'segunda', title: 'Segunda', start: $second->setTimezone(new DateTimeZone('Europe/Madrid')), end: $second->modify('+30 minutes')->setTimezone(new DateTimeZone('Europe/Madrid'))),
    ];

    $ics = icalWrite($events);
    $back = icalTrip($events);

    expect(icalVevent($ics, 'primera@ej.test'))->toContain('DTSTART;TZID=Europe/Madrid:20261025T023000')
        ->and(icalVevent($ics, 'segunda@ej.test'))->toContain('DTSTART:20261025T013000Z')
        ->and($back[0]->start->getTimestamp())->toBe($first->getTimestamp())
        ->and($back[1]->start->getTimestamp())->toBe($second->getTimestamp())
        ->and($back[1]->start->getTimestamp() - $back[0]->start->getTimestamp())->toBe(3600);
});
