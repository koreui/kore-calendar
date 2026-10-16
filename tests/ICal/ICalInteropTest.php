<?php

use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\ICal\ICalReader;

require_once __DIR__.'/helpers.php';

/*
| Archivos escritos a mano imitando lo que exportan Google Calendar y Outlook/Exchange
| (tests/ICal/fixtures). Lo que se comprueba no es solo que se lean, sino que se lean BIEN: las
| zonas, las series que cruzan el cambio de hora y el texto con tildes y emoji que el
| exportador partió con el plegado.
*/

/**
 * @return array{0: array<string, CalendarEvent>, 1: ICalReader}
 */
function icalFixtureEvents(string $name): array
{
    $reader = new ICalReader;
    $events = [];

    foreach ($reader->read(icalFixture($name)) as $event) {
        $events[(string) $event->id] = $event;
    }

    return [$events, $reader];
}

// ── Los fixtures son lo que dicen ser ──────────────────────────────────

it('los fixtures tienen CRLF y caracteres UTF-8 partidos por el plegado, como los de verdad', function (string $name) {
    $raw = icalFixture($name);

    // Sin desplegar no es UTF-8 válido (un pliegue cae dentro de un carácter); desplegado, sí.
    expect(preg_match('//u', $raw))->not->toBe(1)
        ->and(preg_match('//u', preg_replace('/\r\n[ \t]/', '', $raw)))->toBe(1)
        ->and(preg_match('/(?<!\r)\n/', $raw))->toBe(0)
        ->and($raw)->toContain("\r\n ");

    foreach (explode("\r\n", $raw) as $physical) {
        expect(strlen($physical))->toBeLessThanOrEqual(75);
    }
})->with(['google.ics', 'outlook.ics']);

// ── Google Calendar ────────────────────────────────────────────────────

it('Google: se lee entero, sin errores ni avisos (sus TZID son IANA)', function () {
    [$events, $reader] = icalFixtureEvents('google.ics');

    expect($reader->errors())->toBe([])
        ->and($reader->warnings())->toBe([])
        ->and($reader->calendarName())->toBe('Equipo Producto')
        ->and(array_keys($events))->toBe([
            'standup2026abc@google.com', 'standup2026abc@google.com#20261019T090000', 'puente2026@google.com', 'demo-ny-2026@google.com', 'cumple-lucia@google.com',
        ]);
});

it('Google: la serie conserva zona, regla, exclusiones, texto con emoji partido y las propiedades en meta', function () {
    [$events] = icalFixtureEvents('google.ics');
    $standup = $events['standup2026abc@google.com'];

    expect($standup->title)->toBe('Standup de producto 🚀')
        ->and($standup->start->format('c'))->toBe('2026-10-12T09:00:00+02:00')
        ->and($standup->start->getTimezone()->getName())->toBe('Europe/Madrid')
        ->and($standup->end->format('c'))->toBe('2026-10-12T09:30:00+02:00')
        ->and($standup->rrule)->toBe('FREQ=WEEKLY;WKST=MO;UNTIL=20261231T225959Z;BYDAY=MO,WE')
        ->and(array_map(fn ($d) => $d->format('c'), $standup->exdates))->toBe(['2026-10-14T09:00:00+02:00'])
        ->and($standup->meta['description'])->toBe(
            "Revisión diaria del sprint.\nTemas habituales: bloqueos, prioridades y próximos pasos. Si hay demo, traed el entorno ya preparado 🎉 ¡Y no olvidéis las tildes: áéíóú ñ!"
        )
        ->and($standup->meta['location'])->toBe('Sala Ámbar, Planta 2')
        ->and($standup->meta['status'])->toBe('CONFIRMED')
        ->and($standup->meta['sequence'])->toBe(1)
        ->and($standup->meta['organizer'])->toBe(['email' => 'marta@example.com', 'name' => 'Marta Ruiz'])
        // El nombre con coma va entre comillas; el que parte un carácter con el plegado se recompone.
        ->and($standup->meta['attendees'])->toBe([['email' => 'marta@example.com', 'name' => 'Ruiz, Marta'], ['email' => 'aida@example.com', 'name' => 'Aída Peña']])
        ->and($standup->meta['x'])->toBe(['X-GOOGLE-CONFERENCE' => 'https://meet.google.com/abc-defg-hij']);
});

it('Google: el VALARM no deja su DESCRIPTION ni su TRIGGER en el evento', function () {
    [$events] = icalFixtureEvents('google.ics');

    expect(json_encode($events['standup2026abc@google.com']->meta))->not->toContain('event reminder')->not->toContain('TRIGGER');
});

it('Google: la ocurrencia modificada apunta a su serie', function () {
    [$events] = icalFixtureEvents('google.ics');
    $moved = $events['standup2026abc@google.com#20261019T090000'];

    expect($moved->isOverride())->toBeTrue()
        ->and($moved->recurrenceOf)->toBe('standup2026abc@google.com')
        ->and($moved->recurrenceId->format('c'))->toBe('2026-10-19T09:00:00+02:00')
        ->and($moved->start->format('c'))->toBe('2026-10-19T10:00:00+02:00')
        ->and($moved->title)->toBe('Standup de producto (movido)')
        ->and($moved->meta['location'])->toBe('Sala Bruma');
});

it('Google: el día completo, el instante UTC y la serie anual', function () {
    [$events] = icalFixtureEvents('google.ics');

    expect($events['puente2026@google.com']->start)->toBe('2026-10-30')
        ->and($events['puente2026@google.com']->end)->toBe('2026-11-02')
        ->and($events['puente2026@google.com']->allDay)->toBeTrue()
        // DESCRIPTION: y LOCATION: vacíos no dejan claves.
        ->and($events['puente2026@google.com']->meta)->toBe(['status' => 'CONFIRMED', 'sequence' => 0])
        ->and($events['demo-ny-2026@google.com']->start->format('c'))->toBe('2026-10-20T14:00:00+00:00')
        ->and($events['demo-ny-2026@google.com']->title)->toBe('Demo con cliente, Nueva York')
        ->and($events['demo-ny-2026@google.com']->meta['description'])->toBe('Demo con el cliente de Nueva York. Enlace: https://example.com/demo; contraseña en el correo.')
        ->and($events['cumple-lucia@google.com']->rrule)->toBe('FREQ=YEARLY')
        ->and($events['cumple-lucia@google.com']->title)->toBe('Cumpleaños de Lucía 🎂');
});

it('Google: lo que dibuja el calendario — la serie a las 9 de Madrid antes y después del cambio de hora, con la modificada en su sitio', function () {
    [$events] = icalFixtureEvents('google.ics');

    $rows = icalOccurrences(array_values($events), '2026-10-01', '2027-01-15');
    $standups = array_values(array_filter($rows, fn ($r) => str_starts_with($r[5], 'Standup')));

    // Lunes y miércoles desde el 12-oct hasta el 31-dic, sin el miércoles 14-oct (EXDATE), con el
    // lunes 19-oct movido a las 10:00. Cruza el cambio de hora del 25-oct sin moverse de las 9.
    $expected = [];
    foreach (['2026-10-12', '2026-10-19', '2026-10-21', '2026-10-26', '2026-10-28', '2026-11-02', '2026-11-04', '2026-11-09', '2026-11-11', '2026-11-16', '2026-11-18', '2026-11-23', '2026-11-25', '2026-11-30', '2026-12-02', '2026-12-07', '2026-12-09', '2026-12-14', '2026-12-16', '2026-12-21', '2026-12-23', '2026-12-28', '2026-12-30'] as $day) {
        $expected[] = $day === '2026-10-19' ? "{$day} 10:00:00" : "{$day} 09:00:00";
    }

    expect(array_column($standups, 0))->toBe($expected)
        ->and($standups[1][5])->toBe('Standup de producto (movido)')
        ->and(array_column(array_filter($standups, fn ($r) => $r[0] === '2026-10-19 09:00:00'), 0))->toBe([]);
});

it('Google: un instante UTC se ve en la hora de pared de la vista', function () {
    [$events] = icalFixtureEvents('google.ics');

    $demo = array_values(array_filter(icalOccurrences(array_values($events), '2026-10-01', '2026-11-01', 'Europe/Madrid'), fn ($r) => str_starts_with($r[5], 'Demo')));
    $demoNy = array_values(array_filter(icalOccurrences(array_values($events), '2026-10-01', '2026-11-01', 'America/New_York'), fn ($r) => str_starts_with($r[5], 'Demo')));

    expect($demo[0][0])->toBe('2026-10-20 16:00:00')->and($demoNy[0][0])->toBe('2026-10-20 10:00:00');
});

it('Google: el cumpleaños anual aparece el 1 de marzo de 2027', function () {
    [$events] = icalFixtureEvents('google.ics');

    $rows = icalOccurrences(array_values($events), '2027-02-20', '2027-03-10');

    expect(array_column($rows, 0))->toBe(['2027-03-01 00:00:00'])->and($rows[0][2])->toStartWith('allDay');
});

// ── Outlook / Exchange ─────────────────────────────────────────────────

it('Outlook: se lee entero; las dos zonas de Windows se resuelven con su VTIMEZONE y avisan', function () {
    [$events, $reader] = icalFixtureEvents('outlook.ics');

    expect($reader->errors())->toBe([])
        ->and($reader->warnings())->toHaveCount(2)
        ->and($reader->warnings()[0])->toContain('«Romance Standard Time»')->toContain('«Europe/Madrid»')
        ->and($reader->warnings()[1])->toContain('«Pacific Standard Time»')->toContain('«America/Los_Angeles»')
        ->and($reader->calendarName())->toBe('Calendario')
        ->and($events)->toHaveCount(5);
});

it('Outlook: el VTODO se ignora y el VALARM no deja rastro', function () {
    [$events] = icalFixtureEvents('outlook.ics');

    expect(array_keys($events))->not->toContain('todo-outlook-1');

    foreach ($events as $event) {
        expect(json_encode($event->meta))->not->toContain('REMINDER')->not->toContain('TRIGGER');
    }
});

it('Outlook: la serie semanal, con sus propiedades X- y el texto con parámetros de idioma', function () {
    [$events] = icalFixtureEvents('outlook.ics');
    $uid = '040000008200E00074C5B7101A82E00800000000A0B1C2D3E4F5010000000000000000100000009F8E7D6C5B4A39281F0E1D2C3B4A5968';
    $series = $events[$uid];

    expect($series->title)->toBe('Reunión de planificación trimestral')
        ->and($series->start->getTimezone()->getName())->toBe('Europe/Madrid')
        ->and($series->start->format('c'))->toBe('2026-10-20T10:00:00+02:00')
        ->and($series->end->format('c'))->toBe('2026-10-20T11:30:00+02:00')
        ->and($series->rrule)->toBe('FREQ=WEEKLY;UNTIL=20261231T225959Z;INTERVAL=1;BYDAY=TU;WKST=MO')
        ->and(array_map(fn ($d) => $d->format('c'), $series->exdates))->toBe(['2026-10-27T10:00:00+01:00'])
        ->and($series->meta['location'])->toBe('Sala Bruma')
        ->and($series->meta['status'])->toBe('CONFIRMED')
        ->and($series->meta['description'])->toBe("Orden del día\n1. Hitos del trimestre\n2. Riesgos y dependencias\n3. Presupuesto\n\nNo olvidéis traer el portátil y los apuntes del trimestre anterior\n")
        ->and($series->meta['organizer'])->toBe(['email' => 'marta@example.com', 'name' => 'Marta Ruiz'])
        ->and($series->meta['attendees'])->toBe([['email' => 'aida@example.com', 'name' => 'Aída Peña']])
        ->and($series->meta['x']['X-MICROSOFT-CDO-BUSYSTATUS'])->toBe('BUSY')
        ->and($series->meta['x']['X-MICROSOFT-LOCATIONDISPLAYNAME'])->toBe('Sala Bruma')
        ->and($series->meta['x']['X-ALT-DESC'])->toStartWith("<html>\n<head>")->toEndWith('</html>');
});

it('Outlook: la serie cruza el cambio de hora sin moverse de las 10:00, con la excepción y el EXDATE', function () {
    [$events] = icalFixtureEvents('outlook.ics');

    $instances = icalInstances(array_values($events), '2026-10-01', '2027-01-15');
    $series = array_values(array_filter($instances, fn ($i) => str_starts_with($i->event->title, 'Reunión de planificación')));
    usort($series, fn ($a, $b) => $a->range->start->ts <=> $b->range->start->ts);

    // Martes desde el 20-oct hasta el 29-dic. Sin el 27-oct (EXDATE) y con el 3-nov movido al miércoles 4 a las 16:00.
    expect(array_map(fn ($i) => $i->exactStart->format('Y-m-d H:i P'), $series))->toBe([
        '2026-10-20 10:00 +02:00',
        '2026-11-04 16:00 +01:00',
        '2026-11-10 10:00 +01:00',
        '2026-11-17 10:00 +01:00',
        '2026-11-24 10:00 +01:00',
        '2026-12-01 10:00 +01:00',
        '2026-12-08 10:00 +01:00',
        '2026-12-15 10:00 +01:00',
        '2026-12-22 10:00 +01:00',
        '2026-12-29 10:00 +01:00',
    ])->and($series[1]->event->title)->toBe('Reunión de planificación trimestral (aplazada)')
        ->and($series[1]->event->meta['location'])->toBe('Sala Ámbar');
});

it('Outlook: los dos estilos de día completo (VALUE=DATE y hora a 00:00 con X-MICROSOFT-CDO-ALLDAYEVENT) dan días completos', function () {
    [$events] = icalFixtureEvents('outlook.ics');
    $byTitle = fn (string $prefix) => array_values(array_filter($events, fn ($e) => str_starts_with($e->title, $prefix)))[0];
    $festivo = $byTitle('Festivo');
    $formacion = $byTitle('Jornada');

    expect($festivo->start)->toBe('2026-11-12')->and($festivo->end)->toBe('2026-11-13')->and($festivo->allDay)->toBeTrue()
        ->and($formacion->start)->toBe('2026-11-05')->and($formacion->end)->toBe('2026-11-06')->and($formacion->allDay)->toBeTrue()
        ->and($formacion->title)->toBe('Jornada formativa interna del equipo de producto 📚 (todo el día)');
});

it('Outlook: la llamada a San Francisco (Pacific Standard Time) cae a las 18:00 en Madrid', function () {
    [$events] = icalFixtureEvents('outlook.ics');

    $call = array_values(array_filter(icalOccurrences(array_values($events), '2026-11-01', '2026-11-10', 'Europe/Madrid'), fn ($r) => str_starts_with($r[5], 'Llamada')));

    // 09:00 PST (-08:00) del 2-nov = 17:00Z = 18:00 CET (+01:00).
    expect($call[0][0])->toBe('2026-11-02 18:00:00')->and($call[0][1])->toBe('2026-11-02 18:30:00');
});

// ── Ida y vuelta de lo importado ───────────────────────────────────────

it('un archivo de Google exportado de nuevo se lee igual: nada de lo importado se estropea al re-exportar', function (string $name) {
    [$events] = icalFixtureEvents($name);

    $again = (new ICalReader)->read(icalWrite(array_values($events), ['uidDomain' => 'ej.test']), ['uidDomain' => 'ej.test']);
    $original = array_values($events);

    // Los ids de Outlook y Google ya traen su @dominio o no: lo que importa es que las fechas, reglas y exclusiones sean las mismas.
    foreach ($original as $i => $event) {
        $a = icalShape($event);
        $b = icalShape($again[$i]);
        unset($a['id'], $b['id'], $a['recurrenceOf'], $b['recurrenceOf']);

        expect($b)->toBe($a, $event->title);
    }

    expect(icalOccurrences($again, '2026-10-01', '2027-03-31'))->toHaveCount(count(icalOccurrences($original, '2026-10-01', '2027-03-31')));
})->with(['google.ics', 'outlook.ics']);
