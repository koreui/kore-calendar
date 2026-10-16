<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\ICal\ICalWriter;
use KoreUi\Calendar\ICal\Text;

require_once __DIR__.'/helpers.php';

/*
| ICalWriter: cada línea que sale tiene que ser RFC 5545 válido y leerse igual en Google,
| Outlook y Apple. Los tests miran el texto, no solo la ida y vuelta: un archivo que
| nuestro lector entiende y Outlook no, no sirve.
*/

function icalMad(string $wall): DateTimeImmutable
{
    return new DateTimeImmutable($wall, new DateTimeZone('Europe/Madrid'));
}

// ── Estructura ─────────────────────────────────────────────────────────

it('escribe un VCALENDAR con PRODID, VERSION, CALSCALE y el nombre del calendario', function () {
    $ics = icalWrite([CalendarEvent::make(id: 1, title: 'A', start: '2026-10-16')], ['name' => 'Equipo, producto']);

    $lines = icalLines($ics);

    expect(array_slice($lines, 0, 5))->toBe([
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//kore-ui//calendar//ES',
        'CALSCALE:GREGORIAN',
        'X-WR-CALNAME:Equipo\, producto',
    ])->and(end($lines))->toBe('END:VCALENDAR');
});

it('sin nombre no escribe X-WR-CALNAME', function () {
    expect(icalWrite([]))->not->toContain('X-WR-CALNAME');
});

it('un calendario sin eventos es válido', function () {
    expect(icalLines(icalWrite([])))->toBe(['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//kore-ui//calendar//ES', 'CALSCALE:GREGORIAN', 'END:VCALENDAR']);
});

it('termina todas las líneas en CRLF, la última también, y no deja ningún LF suelto', function () {
    $ics = icalWrite([CalendarEvent::make(id: 1, title: "Con\nsalto", start: '2026-10-16 10:00')]);

    expect($ics)->toEndWith("END:VCALENDAR\r\n")
        ->and(preg_match('/(?<!\r)\n/', $ics))->toBe(0)
        ->and(preg_match('/\r(?!\n)/', $ics))->toBe(0);
});

it('un VEVENT lleva UID, DTSTAMP, DTSTART y SUMMARY', function () {
    $block = icalVevent(icalWrite([CalendarEvent::make(id: 'a', title: 'Revisión', start: '2026-10-16 10:00')]), 'a@ej.test');

    expect($block)->toBe([
        'BEGIN:VEVENT',
        'UID:a@ej.test',
        'DTSTAMP:20261016T080000Z',
        'DTSTART:20261016T100000',
        'SUMMARY:Revisión',
        'END:VEVENT',
    ]);
});

// ── UID y DTSTAMP ──────────────────────────────────────────────────────

it('el UID es {id}@{dominio} y es el mismo en cada exportación', function () {
    $events = [CalendarEvent::make(id: 42, title: 'A', start: '2026-10-16')];

    $first = icalWrite($events, ['dtstamp' => '2026-10-16T08:00:00Z']);
    $later = icalWrite($events, ['dtstamp' => '2027-01-01T00:00:00Z']);

    expect($first)->toContain("UID:42@ej.test\r\n")
        ->and(str_replace('20261016T080000Z', '20270101T000000Z', $first))->toBe($later);
});

it('el dominio del UID se elige con uidDomain; por defecto es el host de app.url', function () {
    $events = [CalendarEvent::make(id: 'x', title: 'A', start: '2026-10-16')];

    expect(icalWrite($events, ['uidDomain' => 'equipo.example.com']))->toContain('UID:x@equipo.example.com');

    config(['app.url' => 'https://calendario.miempresa.es:8443/app']);
    expect((new ICalWriter)->write($events))->toContain('UID:x@calendario.miempresa.es');
});

it('el DTSTAMP se puede inyectar como string o como fecha, y por defecto es ahora en UTC', function () {
    $events = [CalendarEvent::make(id: 1, title: 'A', start: '2026-10-16')];

    expect(icalWrite($events, ['dtstamp' => '2026-10-16T10:00:00+02:00']))->toContain('DTSTAMP:20261016T080000Z')
        ->and(icalWrite($events, ['dtstamp' => icalMad('2026-10-16 10:00')]))->toContain('DTSTAMP:20261016T080000Z');

    CarbonImmutable::setTestNow('2026-12-24 12:34:56');
    expect((new ICalWriter)->write($events))->toContain('DTSTAMP:20261224T123456Z');
    CarbonImmutable::setTestNow();
});

// ── Las cuatro representaciones de fecha ───────────────────────────────

it('día completo: VALUE=DATE y fin exclusivo, tal como en CalendarEvent', function () {
    $block = icalVevent(icalWrite([CalendarEvent::make(id: 'a', title: 'Congreso', start: '2026-10-14', end: '2026-10-17')]), 'a@ej.test');

    expect($block)->toContain('DTSTART;VALUE=DATE:20261014', 'DTEND;VALUE=DATE:20261017');
});

it('día completo explícito con fechas con hora: se queda con el día, no convierte de zona', function () {
    // 00:30 en Tokio es el día anterior en UTC; un día completo es un día de calendario.
    $start = new DateTimeImmutable('2026-10-16 00:30', new DateTimeZone('Asia/Tokyo'));
    $block = icalVevent(icalWrite([CalendarEvent::make(id: 'a', title: 'A', start: $start, end: $start->modify('+2 days'), allDay: true)]), 'a@ej.test');

    expect($block)->toContain('DTSTART;VALUE=DATE:20261016', 'DTEND;VALUE=DATE:20261018');
});

it('con zona IANA: DTSTART;TZID=… en hora de pared de esa zona, y un VTIMEZONE', function () {
    $ics = icalWrite([CalendarEvent::make(id: 'a', title: 'A', start: icalMad('2026-10-16 09:00'), end: icalMad('2026-10-16 10:30'))]);

    expect(icalVevent($ics, 'a@ej.test'))->toContain('DTSTART;TZID=Europe/Madrid:20261016T090000', 'DTEND;TZID=Europe/Madrid:20261016T103000')
        ->and(icalBlocks($ics, 'VTIMEZONE'))->toHaveCount(1)
        ->and(icalBlocks($ics, 'VTIMEZONE')[0])->toContain('TZID:Europe/Madrid');
});

it('un instante en otra zona se escribe en la suya, no en la del servidor', function () {
    $ny = new DateTimeImmutable('2026-10-16 09:00', new DateTimeZone('America/New_York'));

    expect(icalVevent(icalWrite([CalendarEvent::make(id: 'a', title: 'A', start: $ny)]), 'a@ej.test'))->toContain('DTSTART;TZID=America/New_York:20261016T090000');
});

it('UTC: DTSTART con Z, venga de una fecha UTC, de un string con Z o de un +00:00', function () {
    $utc = new DateTimeImmutable('2026-10-16 07:00', new DateTimeZone('UTC'));

    foreach ([$utc, '2026-10-16T07:00:00Z', '2026-10-16T07:00:00+00:00', CarbonImmutable::parse('2026-10-16 07:00', 'UTC')] as $i => $start) {
        expect(icalVevent(icalWrite([CalendarEvent::make(id: "e{$i}", title: 'A', start: $start)]), "e{$i}@ej.test"))->toContain('DTSTART:20261016T070000Z');
    }
});

it('un offset fijo en un evento suelto se escribe como instante UTC', function () {
    $ics = icalWrite([CalendarEvent::make(id: 'a', title: 'A', start: '2026-10-16T09:00:00+02:00', end: '2026-10-16T10:00:00+02:00')]);

    expect(icalVevent($ics, 'a@ej.test'))->toContain('DTSTART:20261016T070000Z', 'DTEND:20261016T080000Z')
        ->and(icalBlocks($ics, 'VTIMEZONE'))->toBe([]);
});

it('flotante: un string sin offset se escribe sin Z ni TZID', function () {
    $ics = icalWrite([CalendarEvent::make(id: 'a', title: 'A', start: '2026-10-16 10:00', end: '2026-10-16 11:15:30')]);

    expect(icalVevent($ics, 'a@ej.test'))->toContain('DTSTART:20261016T100000', 'DTEND:20261016T111530')
        ->and(icalBlocks($ics, 'VTIMEZONE'))->toBe([]);
});

it('un string de solo fecha con allDay false es una hora flotante a medianoche', function () {
    expect(icalVevent(icalWrite([CalendarEvent::make(id: 'a', title: 'A', start: '2026-10-16', allDay: false)]), 'a@ej.test'))->toContain('DTSTART:20261016T000000');
});

// ── Fin ────────────────────────────────────────────────────────────────

it('un evento sin fin no escribe DTEND ni DURATION: es lo que RFC 5545 define como sin fin', function () {
    $block = icalVevent(icalWrite([
        CalendarEvent::make(id: 'a', title: 'Con hora', start: '2026-10-16 10:00'),
        CalendarEvent::make(id: 'b', title: 'Día', start: '2026-10-16'),
    ]), 'a@ej.test');

    expect(implode("\n", $block))->not->toContain('DTEND')->not->toContain('DURATION');
});

it('defaultDuration añade un DURATION a los eventos con hora sin fin, y a los de día completo no', function () {
    $ics = icalWrite([
        CalendarEvent::make(id: 'a', title: 'A', start: '2026-10-16 10:00'),
        CalendarEvent::make(id: 'b', title: 'B', start: '2026-10-16'),
        CalendarEvent::make(id: 'c', title: 'C', start: '2026-10-16 10:00', end: '2026-10-16 12:00'),
    ], ['defaultDuration' => 5400]);

    expect(icalVevent($ics, 'a@ej.test'))->toContain('DURATION:PT1H30M')
        ->and(implode("\n", icalVevent($ics, 'b@ej.test')))->not->toContain('DURATION')
        ->and(implode("\n", icalVevent($ics, 'c@ej.test')))->not->toContain('DURATION')
        ->and(icalWrite([CalendarEvent::make(id: 'a', title: 'A', start: '2026-10-16 10:00')], ['defaultDuration' => 45]))->toContain('DURATION:PT45S');
});

it('un fin que no es posterior al inicio se omite: el normalizador lo trata como ausente y iCalendar no admite DTEND ≤ DTSTART', function () {
    $ics = icalWrite([
        CalendarEvent::make(id: 'a', title: 'A', start: '2026-10-16 10:00', end: '2026-10-16 09:00'),
        CalendarEvent::make(id: 'b', title: 'B', start: '2026-10-16', end: '2026-10-16'),
        CalendarEvent::make(id: 'c', title: 'C', start: icalMad('2026-10-25 02:30'), end: icalMad('2026-10-25 01:30')),
    ]);

    foreach (['a', 'b', 'c'] as $id) {
        expect(implode("\n", icalVevent($ics, "{$id}@ej.test")))->not->toContain('DTEND');
    }
});

// ── Series ─────────────────────────────────────────────────────────────

it('RRULE sin DTSTART y sin el prefijo; el inicio es el DTSTART del evento', function () {
    $block = icalVevent(icalWrite([CalendarEvent::make(id: 's', title: 'S', start: icalMad('2026-10-12 09:00'), rrule: 'FREQ=WEEKLY;BYDAY=MO;COUNT=4')]), 's@ej.test');

    expect($block)->toContain('RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=4')
        ->and(implode("\n", $block))->not->toContain('RRULE:DTSTART')->not->toContain('RRULE:RRULE');
});

it('UNTIL se escribe en la representación del inicio (RFC 5545 §3.3.10)', function () {
    $ics = icalWrite([
        // Con zona: UTC. «Hasta el 31» es hasta el final de ese día en Madrid (22:59:59Z en invierno).
        CalendarEvent::make(id: 'zona', title: 'A', start: icalMad('2026-10-12 09:00'), rrule: 'FREQ=WEEKLY;UNTIL=20261231'),
        CalendarEvent::make(id: 'zonaz', title: 'B', start: icalMad('2026-10-12 09:00'), rrule: 'FREQ=WEEKLY;UNTIL=20261231T225959Z'),
        // Día completo: una fecha.
        CalendarEvent::make(id: 'dia', title: 'C', start: '2026-10-12', rrule: 'FREQ=WEEKLY;UNTIL=20261231T000000Z'),
        // Flotante: hora local, sin Z.
        CalendarEvent::make(id: 'flot', title: 'D', start: '2026-10-12 09:00', rrule: 'FREQ=WEEKLY;UNTIL=20261231'),
        CalendarEvent::make(id: 'flotz', title: 'E', start: '2026-10-12 09:00', rrule: 'FREQ=WEEKLY;UNTIL=20261231T100000Z'),
        // UTC: sin tocar.
        CalendarEvent::make(id: 'utc', title: 'F', start: '2026-10-12T09:00:00Z', rrule: 'FREQ=WEEKLY;UNTIL=20261231'),
    ]);

    $until = fn (string $id) => collect(icalVevent($ics, "{$id}@ej.test"))->first(fn ($l) => str_starts_with($l, 'RRULE:'));

    expect($until('zona'))->toBe('RRULE:FREQ=WEEKLY;UNTIL=20261231T225959Z')
        ->and($until('zonaz'))->toBe('RRULE:FREQ=WEEKLY;UNTIL=20261231T225959Z')
        ->and($until('dia'))->toBe('RRULE:FREQ=WEEKLY;UNTIL=20261231')
        ->and($until('flot'))->toBe('RRULE:FREQ=WEEKLY;UNTIL=20261231T235959')
        ->and($until('flotz'))->toBe('RRULE:FREQ=WEEKLY;UNTIL=20261231T100000')
        ->and($until('utc'))->toBe('RRULE:FREQ=WEEKLY;UNTIL=20261231T235959Z');
});

it('una serie con offset fijo conserva su hora de pared con un TZID propio; el instante suelto no hace falta', function () {
    // Lunes 01:00+02:00 es domingo 23:00 en UTC: escribirla en UTC cambiaría el BYDAY.
    $ics = icalWrite([CalendarEvent::make(id: 's', title: 'S', start: '2026-10-12T01:00:00+02:00', rrule: 'FREQ=WEEKLY;BYDAY=MO;COUNT=3')]);

    expect(icalVevent($ics, 's@ej.test'))->toContain('DTSTART;TZID=UTC+0200:20261012T010000')
        ->and(icalBlocks($ics, 'VTIMEZONE')[0])->toContain('TZID:UTC+0200', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0200');
});

it('un offset con minutos (+05:30) también: UTC+0530', function () {
    $ics = icalWrite([CalendarEvent::make(id: 's', title: 'S', start: '2026-10-12T10:00:00+05:30', rrule: 'FREQ=DAILY;COUNT=2')]);

    expect(icalVevent($ics, 's@ej.test'))->toContain('DTSTART;TZID=UTC+0530:20261012T100000');
});

it('EXDATE va en la misma representación que el DTSTART', function () {
    $ics = icalWrite([
        CalendarEvent::make(id: 'zona', title: 'A', start: icalMad('2026-10-12 09:00'), rrule: 'FREQ=DAILY;COUNT=9', exdates: ['2026-10-14 09:00:00', icalMad('2026-10-15 09:00')]),
        CalendarEvent::make(id: 'dia', title: 'B', start: '2026-10-12', rrule: 'FREQ=DAILY;COUNT=9', exdates: ['2026-10-14', '2026-10-16 00:00:00']),
        CalendarEvent::make(id: 'flot', title: 'C', start: '2026-10-12 09:00', rrule: 'FREQ=DAILY;COUNT=9', exdates: ['2026-10-14 09:00:00']),
        CalendarEvent::make(id: 'utc', title: 'D', start: '2026-10-12T07:00:00Z', rrule: 'FREQ=DAILY;COUNT=9', exdates: ['2026-10-14T07:00:00Z']),
    ]);

    expect(icalVevent($ics, 'zona@ej.test'))->toContain('EXDATE;TZID=Europe/Madrid:20261014T090000,20261015T090000')
        ->and(icalVevent($ics, 'dia@ej.test'))->toContain('EXDATE;VALUE=DATE:20261014,20261016')
        ->and(icalVevent($ics, 'flot@ej.test'))->toContain('EXDATE:20261014T090000')
        ->and(icalVevent($ics, 'utc@ej.test'))->toContain('EXDATE:20261014T070000Z');
});

it('un EXDATE en otra zona o un string sin offset se lleva a la zona de la serie, como lo compara el expansor', function () {
    $ics = icalWrite([CalendarEvent::make(
        id: 's', title: 'S', start: icalMad('2026-10-12 09:00'), rrule: 'FREQ=DAILY;COUNT=9',
        // 07:00Z = 09:00 en Madrid ese día; el string sin offset es hora de Madrid.
        exdates: ['2026-10-14T07:00:00Z', '2026-10-15 09:00:00', new DateTimeImmutable('2026-10-16 03:00', new DateTimeZone('America/New_York'))],
    )]);

    expect(icalVevent($ics, 's@ej.test'))->toContain('EXDATE;TZID=Europe/Madrid:20261014T090000,20261015T090000,20261016T090000');
});

it('un EXDATE de solo fecha en una serie con hora se resuelve a la hora real de esa ocurrencia', function () {
    // «Excluye la ocurrencia de ese día, sea a la hora que sea»: iCalendar no tiene esa forma.
    $ics = icalWrite([
        CalendarEvent::make(id: 'zona', title: 'A', start: icalMad('2026-10-12 09:00'), rrule: 'FREQ=DAILY;COUNT=9', exdates: ['2026-10-14']),
        CalendarEvent::make(id: 'flot', title: 'B', start: '2026-10-12 09:30', rrule: 'FREQ=DAILY;COUNT=9', exdates: ['2026-10-14']),
        // Ese día no hay ocurrencia: nada que excluir.
        CalendarEvent::make(id: 'nada', title: 'C', start: icalMad('2026-10-12 09:00'), rrule: 'FREQ=WEEKLY;COUNT=9', exdates: ['2026-10-14']),
    ]);

    expect(icalVevent($ics, 'zona@ej.test'))->toContain('EXDATE;TZID=Europe/Madrid:20261014T090000')
        ->and(icalVevent($ics, 'flot@ej.test'))->toContain('EXDATE:20261014T093000')
        ->and(implode("\n", icalVevent($ics, 'nada@ej.test')))->not->toContain('EXDATE');
});

it('una excepción lleva el UID de su serie y un RECURRENCE-ID en la representación de la serie', function () {
    $ics = icalWrite([
        // La excepción, antes que su serie: el orden de la lista no importa.
        CalendarEvent::make(id: 'mv', title: 'Movido', start: icalMad('2026-10-19 10:00'), recurrenceOf: 's', recurrenceId: '2026-10-19 09:00:00'),
        CalendarEvent::make(id: 's', title: 'Serie', start: icalMad('2026-10-12 09:00'), rrule: 'FREQ=WEEKLY;COUNT=4'),
    ]);

    $override = icalVevent($ics, 's@ej.test', override: true);

    expect($override)->toContain('UID:s@ej.test', 'RECURRENCE-ID;TZID=Europe/Madrid:20261019T090000', 'DTSTART;TZID=Europe/Madrid:20261019T100000', 'SUMMARY:Movido')
        ->and(implode("\n", $override))->not->toContain('RRULE')
        ->and(icalBlocks($ics, 'VEVENT'))->toHaveCount(2);
});

it('el RECURRENCE-ID de solo fecha en una serie de día completo es VALUE=DATE; en una serie con hora, la hora real', function () {
    $ics = icalWrite([
        CalendarEvent::make(id: 'dia', title: 'D', start: '2026-10-12', rrule: 'FREQ=DAILY;COUNT=5'),
        CalendarEvent::make(id: 'dia-mv', title: 'D2', start: '2026-10-20', recurrenceOf: 'dia', recurrenceId: '2026-10-14'),
        CalendarEvent::make(id: 'hora', title: 'H', start: icalMad('2026-10-12 09:00'), rrule: 'FREQ=DAILY;COUNT=5'),
        CalendarEvent::make(id: 'hora-mv', title: 'H2', start: icalMad('2026-10-14 11:00'), recurrenceOf: 'hora', recurrenceId: '2026-10-14'),
    ]);

    expect(icalVevent($ics, 'dia@ej.test', override: true))->toContain('RECURRENCE-ID;VALUE=DATE:20261014', 'DTSTART;VALUE=DATE:20261020')
        ->and(icalVevent($ics, 'hora@ej.test', override: true))->toContain('RECURRENCE-ID;TZID=Europe/Madrid:20261014T090000', 'DTSTART;TZID=Europe/Madrid:20261014T110000');
});

it('una excepción cuya serie no está en la lista viaja con la representación de su RECURRENCE-ID', function () {
    $ics = icalWrite([
        CalendarEvent::make(id: 'a', title: 'A', start: icalMad('2026-10-19 10:00'), recurrenceOf: 'otra', recurrenceId: icalMad('2026-10-19 09:00')),
        CalendarEvent::make(id: 'b', title: 'B', start: '2026-10-19', recurrenceOf: 'otra2', recurrenceId: '2026-10-18'),
        CalendarEvent::make(id: 'c', title: 'C', start: '2026-10-19 10:00', recurrenceOf: 'otra3', recurrenceId: '2026-10-19 09:00'),
    ]);

    expect(icalVevent($ics, 'otra@ej.test', override: true))->toContain('RECURRENCE-ID;TZID=Europe/Madrid:20261019T090000')
        ->and(icalVevent($ics, 'otra2@ej.test', override: true))->toContain('RECURRENCE-ID;VALUE=DATE:20261018')
        ->and(icalVevent($ics, 'otra3@ej.test', override: true))->toContain('RECURRENCE-ID:20261019T090000');
});

// ── Propiedades ────────────────────────────────────────────────────────

it('escribe URL, COLOR (RFC 7986) y los recursos en X-KORE-RESOURCES con sus comas escapadas', function () {
    $block = icalVevent(icalWrite([CalendarEvent::make(
        id: 'a', title: 'A', start: '2026-10-16', url: 'https://example.com/e?id=1,2&x=3', color: 'chart-3', resourceIds: ['ambar', 7, 'sala, grande'],
    )]), 'a@ej.test');

    expect($block)->toContain('URL:https://example.com/e?id=1,2&x=3', 'X-KORE-COLOR:chart-3', 'X-KORE-RESOURCES:ambar,7,sala\, grande')
        ->and($block)->not->toContain("\r\nCOLOR:");
});

it('escribe del meta lo que iCalendar representa: description, location, status, categories, sequence y x', function () {
    $block = icalVevent(icalWrite([CalendarEvent::make(
        id: 'a', title: 'A', start: '2026-10-16',
        meta: [
            'description' => "Línea 1\nLínea 2; con, comas", 'location' => 'Sala A', 'status' => 'confirmed',
            'categories' => ['Trabajo', 'Equipo, A'], 'sequence' => 3,
            'x' => ['x-google-conference' => 'https://meet.example/abc', 'NO-ES-X' => 'no', 'X-KORE-RESOURCES' => 'no'],
            'otra' => 'no se exporta',
        ],
    )]), 'a@ej.test');

    expect($block)->toContain(
        'DESCRIPTION:Línea 1\nLínea 2\; con\, comas',
        'LOCATION:Sala A',
        'STATUS:CONFIRMED',
        'CATEGORIES:Trabajo,Equipo\, A',
        'SEQUENCE:3',
        'X-GOOGLE-CONFERENCE:https://meet.example/abc',
    )->and(implode("\n", $block))->not->toContain('NO-ES-X')->not->toContain('no se exporta')->not->toContain('X-KORE-RESOURCES');
});

it('un status que no es de iCalendar no se escribe', function () {
    expect(icalWrite([CalendarEvent::make(id: 'a', title: 'A', start: '2026-10-16', meta: ['status' => 'pendiente'])]))->not->toContain('STATUS');
});

// ── Escapado ───────────────────────────────────────────────────────────

it('escapa \\, ;, , y los saltos de línea del texto', function () {
    expect(Text::escape("a\\b;c,d\ne\r\nf\rg"))->toBe('a\\\\b\;c\\,d\\ne\\nf\\ng');

    $block = icalVevent(icalWrite([CalendarEvent::make(id: 'a', title: "Ruta C:\\tmp; sala 1, 2\nfin", start: '2026-10-16')]), 'a@ej.test');

    expect($block)->toContain('SUMMARY:Ruta C:\\\\tmp\; sala 1\\, 2\\nfin');
});

it('un título hostil no puede añadir propiedades al VEVENT', function () {
    $ics = icalWrite([CalendarEvent::make(
        id: 'a', title: "Reunión\r\nATTENDEE:mailto:evil@example.com\r\nBEGIN:VALARM",
        start: '2026-10-16', url: "https://example.com/\r\nORGANIZER:mailto:evil@example.com",
        meta: ['location' => "Sala\nX-EVIL:1", 'x' => ['X-A' => "1\r\nATTENDEE:mailto:evil@example.com"]],
    )]);

    $lines = icalLines($ics);

    expect(array_filter($lines, fn ($l) => str_starts_with($l, 'ATTENDEE') || str_starts_with($l, 'ORGANIZER') || str_starts_with($l, 'X-EVIL') || $l === 'BEGIN:VALARM'))->toBe([])
        ->and(icalBlocks($ics, 'VEVENT'))->toHaveCount(1);
});

// ── Plegado ────────────────────────────────────────────────────────────

it('pliega a 75 octetos, con CRLF y un espacio, y al desplegar sale la línea original', function () {
    $title = str_repeat('Planificación trimestral del equipo de producto. ', 8);
    $ics = icalWrite([CalendarEvent::make(id: 'a', title: $title, start: '2026-10-16')]);

    foreach (explode("\r\n", rtrim($ics, "\r\n")) as $physical) {
        expect(strlen($physical))->toBeLessThanOrEqual(75);
    }

    expect($ics)->toContain("\r\n ")
        ->and(icalVevent($ics, 'a@ej.test'))->toContain('SUMMARY:'.$title);
});

it('nunca parte un carácter UTF-8 al plegar: tildes, eñes y emoji de cuatro bytes en cada posición', function () {
    // Con cada relleno el carácter multibyte cae en otro byte de la frontera de 75.
    foreach (['á', 'ñ', '€', '🎉'] as $char) {
        for ($pad = 0; $pad < 8; $pad++) {
            $line = 'SUMMARY:'.str_repeat('x', 60 + $pad).$char.$char.str_repeat('y', 10).$char;
            $folded = Text::fold($line);

            foreach (explode("\r\n", rtrim($folded, "\r\n")) as $physical) {
                expect(strlen($physical))->toBeLessThanOrEqual(75)
                    ->and(preg_match('//u', $physical))->toBe(1);
            }

            expect(str_replace("\r\n ", '', rtrim($folded, "\r\n")))->toBe($line);
        }
    }
});

it('una línea de exactamente 75 octetos no se pliega y una de 76 sí', function () {
    expect(Text::fold(str_repeat('a', 75)))->toBe(str_repeat('a', 75)."\r\n")
        ->and(Text::fold(str_repeat('a', 76)))->toBe(str_repeat('a', 75)."\r\n a\r\n");
});

it('las continuaciones llevan 74 octetos de contenido más el espacio', function () {
    $physical = explode("\r\n", rtrim(Text::fold(str_repeat('a', 75 + 74 + 74 + 1)), "\r\n"));

    expect(array_map('strlen', $physical))->toBe([75, 75, 75, 2]);
});

it('en un archivo real, plegar y desplegar con emoji no pierde ni un byte', function () {
    $description = str_repeat('Revisión 🎉 de áéíóú ñ y 日本語. ', 12);
    $ics = icalWrite([CalendarEvent::make(id: 'a', title: 'A', start: '2026-10-16', meta: ['description' => $description])]);

    expect(preg_match('//u', $ics))->toBe(1)
        ->and(icalVevent($ics, 'a@ej.test'))->toContain('DESCRIPTION:'.$description);
});

// ── Errores y opciones ─────────────────────────────────────────────────

it('acepta arrays (los de una fuente) además de CalendarEvent', function () {
    $ics = icalWrite([['id' => 'a', 'title' => 'Desde array', 'start' => '2026-10-16']]);

    expect(icalVevent($ics, 'a@ej.test'))->toContain('SUMMARY:Desde array');
});

it('lanza si algo de la lista no es un evento', function () {
    expect(fn () => icalWrite(['no soy un evento']))->toThrow(InvalidArgumentException::class, 'ICalWriter::write() espera CalendarEvent');
});

it('lanza con una opción desconocida y lista las válidas', function () {
    expect(fn () => icalWrite([], ['dtstmp' => 'x']))->toThrow(InvalidArgumentException::class, 'opción desconocida «dtstmp». Permitidas: name, uidDomain, dtstamp, defaultDuration');
});

it('defaultDuration solo admite segundos positivos', function () {
    expect(fn () => icalWrite([], ['defaultDuration' => 0]))->toThrow(InvalidArgumentException::class, 'defaultDuration')
        ->and(fn () => icalWrite([], ['defaultDuration' => '1h']))->toThrow(InvalidArgumentException::class, 'defaultDuration');
});

it('una fecha inválida lanza con el id del evento, igual que el normalizador', function () {
    expect(fn () => icalWrite([CalendarEvent::make(id: 'roto', title: 'A', start: '2026-02-30 10:00')]))
        ->toThrow(InvalidArgumentException::class, 'Evento «roto»: `start` «2026-02-30 10:00» no es una fecha válida');
});

it('acepta un generador y no lo recorre dos veces', function () {
    $generator = (function () {
        yield CalendarEvent::make(id: 1, title: 'A', start: '2026-10-16');
        yield CalendarEvent::make(id: 2, title: 'B', start: '2026-10-17');
    })();

    expect(icalBlocks(icalWrite($generator), 'VEVENT'))->toHaveCount(2);
});

it('es determinista: el mismo conjunto, en cualquier orden de zonas, da el mismo VTIMEZONE ordenado', function () {
    $madrid = CalendarEvent::make(id: 'm', title: 'M', start: icalMad('2026-10-16 09:00'));
    $ny = CalendarEvent::make(id: 'n', title: 'N', start: new DateTimeImmutable('2026-10-16 09:00', new DateTimeZone('America/New_York')));

    $ids = fn (string $ics) => array_map(fn ($b) => $b[1], icalBlocks($ics, 'VTIMEZONE'));

    expect($ids(icalWrite([$madrid, $ny])))->toBe(['TZID:America/New_York', 'TZID:Europe/Madrid'])
        ->and($ids(icalWrite([$ny, $madrid])))->toBe(['TZID:America/New_York', 'TZID:Europe/Madrid']);
});

it('COLOR solo lleva nombres CSS3; un token del tema va solo en X-KORE-COLOR', function () {
    $ics = (new ICalWriter)->write([
        CalendarEvent::make(id: 'a', title: 'A', start: '2026-10-16', color: 'Tomato'),
        CalendarEvent::make(id: 'b', title: 'B', start: '2026-10-16', color: 'oklch(0.7 0.1 200)'),
    ]);

    expect($ics)->toContain("X-KORE-COLOR:Tomato\r\nCOLOR:tomato\r\n")
        ->and($ics)->toContain('X-KORE-COLOR:oklch(0.7 0.1 200)')
        ->and(substr_count($ics, "\r\nCOLOR:"))->toBe(1);
});
