<?php

use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\ICal\ICalException;
use KoreUi\Calendar\ICal\ICalReader;

require_once __DIR__.'/helpers.php';

/**
 * @return array{0: list<CalendarEvent>, 1: ICalReader}
 */
function icalRead(string $ics, array $options = []): array
{
    $reader = new ICalReader;

    return [$reader->read($ics, $options), $reader];
}

/** El único evento de un archivo con un VEVENT. */
function icalOne(string $ics, array $options = []): CalendarEvent
{
    [$events] = icalRead($ics, $options);
    expect($events)->toHaveCount(1);

    return $events[0];
}

function icalRomance(): string
{
    return implode("\n", [
        'BEGIN:VTIMEZONE', 'TZID:Romance Standard Time',
        'BEGIN:STANDARD', 'DTSTART:16010101T030000', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'RRULE:FREQ=YEARLY;INTERVAL=1;BYDAY=-1SU;BYMONTH=10', 'END:STANDARD',
        'BEGIN:DAYLIGHT', 'DTSTART:16010101T020000', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'RRULE:FREQ=YEARLY;INTERVAL=1;BYDAY=-1SU;BYMONTH=3', 'END:DAYLIGHT',
        'END:VTIMEZONE',
    ]);
}

// ── Las cuatro representaciones ────────────────────────────────────────

it('VALUE=DATE es un día completo, con fin exclusivo', function () {
    $e = icalOne(icalDoc(icalEvent('UID:a', 'DTSTART;VALUE=DATE:20261014', 'DTEND;VALUE=DATE:20261017', 'SUMMARY:Congreso')));

    expect($e->start)->toBe('2026-10-14')->and($e->end)->toBe('2026-10-17')->and($e->allDay)->toBeTrue()->and($e->title)->toBe('Congreso');
});

it('una fecha sin VALUE=DATE pero sin hora también es un día completo', function () {
    $e = icalOne(icalDoc(icalEvent('UID:a', 'DTSTART:20261014', 'SUMMARY:A')));

    expect($e->start)->toBe('2026-10-14')->and($e->allDay)->toBeTrue()->and($e->end)->toBeNull();
});

it('sin sufijo es una hora de pared flotante, como string sin zona', function () {
    $e = icalOne(icalDoc(icalEvent('UID:a', 'DTSTART:20261016T090000', 'DTEND:20261016T101500', 'SUMMARY:A')));

    expect($e->start)->toBe('2026-10-16 09:00:00')->and($e->end)->toBe('2026-10-16 10:15:00')->and($e->allDay)->toBeNull();
});

it('con Z es un instante UTC', function () {
    $e = icalOne(icalDoc(icalEvent('UID:a', 'DTSTART:20261016T070000Z', 'DTEND:20261016T080000Z', 'SUMMARY:A')));

    expect($e->start)->toBeInstanceOf(DateTimeImmutable::class)
        ->and($e->start->format('c'))->toBe('2026-10-16T07:00:00+00:00')
        ->and($e->start->getTimezone()->getName())->toBe('UTC')
        ->and($e->end->getTimestamp() - $e->start->getTimestamp())->toBe(3600);
});

it('con TZID es una fecha en esa zona de PHP, con su cambio de hora', function () {
    $e = icalOne(icalDoc(icalEvent('UID:a', 'DTSTART;TZID=Europe/Madrid:20261016T090000', 'DTEND;TZID=Europe/Madrid:20261016T100000', 'SUMMARY:A')));

    expect($e->start->getTimezone()->getName())->toBe('Europe/Madrid')
        ->and($e->start->format('c'))->toBe('2026-10-16T09:00:00+02:00')
        ->and($e->start->getTimestamp())->toBe(gmmktime(7, 0, 0, 10, 16, 2026));
});

it('un TZID entre comillas, con un nombre IANA que tiene barra, se lee igual', function () {
    $e = icalOne(icalDoc(icalEvent('UID:a', 'DTSTART;TZID="America/New_York":20261102T090000', 'SUMMARY:A')));

    expect($e->start->getTimezone()->getName())->toBe('America/New_York')->and($e->start->format('c'))->toBe('2026-11-02T09:00:00-05:00');
});

it('una hora que se repite en otoño es la primera vuelta, y una que no existe en primavera se resuelve hacia delante (RFC 5545 §3.3.5)', function () {
    [$e] = icalRead(icalDoc(
        icalEvent('UID:fold', 'DTSTART;TZID=Europe/Madrid:20261025T023000', 'SUMMARY:A'),
        icalEvent('UID:gap', 'DTSTART;TZID=Europe/Madrid:20260329T023000', 'SUMMARY:B'),
        // Nueva York repite la 01:30 el 1-nov: PHP daría aquí la primera y en Madrid la segunda; la regla es la misma en todas.
        icalEvent('UID:ny', 'DTSTART;TZID=America/New_York:20261101T013000', 'SUMMARY:C'),
    ));

    expect($e[0]->start->format('c'))->toBe('2026-10-25T02:30:00+02:00')
        ->and($e[1]->start->format('c'))->toBe('2026-03-29T03:30:00+02:00')
        ->and($e[2]->start->format('c'))->toBe('2026-11-01T01:30:00-04:00');
});

it('una Z gana al TZID: la hora ya es un instante', function () {
    $e = icalOne(icalDoc(icalEvent('UID:a', 'DTSTART;TZID=Europe/Madrid:20261016T070000Z', 'SUMMARY:A')));

    expect($e->start->getTimezone()->getName())->toBe('UTC');
});

it('TZID con un desplazamiento (UTC+0200, GMT+01:00) o con el prefijo global de RFC 5545', function () {
    $doc = icalDoc(
        icalEvent('UID:a', 'DTSTART;TZID=UTC+0200:20261012T100000', 'SUMMARY:A'),
        icalEvent('UID:b', 'DTSTART;TZID="GMT+01:00":20261012T100000', 'SUMMARY:B'),
        icalEvent('UID:c', 'DTSTART;TZID="/mozilla.org/20050126_1/Europe/Madrid":20261012T100000', 'SUMMARY:C'),
    );

    [$events, $reader] = icalRead($doc);

    expect($events[0]->start->format('c'))->toBe('2026-10-12T10:00:00+02:00')
        ->and($events[1]->start->format('c'))->toBe('2026-10-12T10:00:00+01:00')
        ->and($events[2]->start->getTimezone()->getName())->toBe('Europe/Madrid')
        ->and($reader->warnings())->toBe([]);
});

it('DURATION calcula el fin en cada representación', function () {
    $doc = icalDoc(
        icalEvent('UID:flot', 'DTSTART:20261016T100000', 'DURATION:PT1H30M', 'SUMMARY:A'),
        icalEvent('UID:dia', 'DTSTART;VALUE=DATE:20261016', 'DURATION:P3D', 'SUMMARY:B'),
        icalEvent('UID:sem', 'DTSTART;VALUE=DATE:20261016', 'DURATION:P1W', 'SUMMARY:C'),
        icalEvent('UID:utc', 'DTSTART:20261016T100000Z', 'DURATION:PT45S', 'SUMMARY:D'),
        icalEvent('UID:neg', 'DTSTART:20261016T100000', 'DURATION:-PT1H', 'SUMMARY:E'),
    );

    [$e] = icalRead($doc);

    expect($e[0]->end)->toBe('2026-10-16 11:30:00')
        ->and($e[1]->end)->toBe('2026-10-19')
        ->and($e[2]->end)->toBe('2026-10-23')
        ->and($e[3]->end->format('c'))->toBe('2026-10-16T10:00:45+00:00')
        ->and($e[4]->end)->toBe('2026-10-16 09:00:00');
});

it('en una zona, P1D es un día de calendario y PT24H son 24 horas reales (el cambio de hora de Madrid es el 25-oct)', function () {
    [$e] = icalRead(icalDoc(
        icalEvent('UID:dia', 'DTSTART;TZID=Europe/Madrid:20261024T120000', 'DURATION:P1D', 'SUMMARY:A'),
        icalEvent('UID:horas', 'DTSTART;TZID=Europe/Madrid:20261024T120000', 'DURATION:PT24H', 'SUMMARY:B'),
    ));

    expect($e[0]->end->format('Y-m-d H:i'))->toBe('2026-10-25 12:00')
        ->and($e[1]->end->format('Y-m-d H:i'))->toBe('2026-10-25 11:00');
});

it('sin DTEND ni DURATION no hay fin', function () {
    expect(icalOne(icalDoc(icalEvent('UID:a', 'DTSTART:20261016T100000', 'SUMMARY:A')))->end)->toBeNull();
});

it('un fin de día completo con hora se queda con el día', function () {
    $e = icalOne(icalDoc(icalEvent('UID:a', 'DTSTART;VALUE=DATE:20261016', 'DTEND:20261018T000000Z', 'SUMMARY:A')));

    expect($e->end)->toBe('2026-10-18');
});

it('Outlook marca un día completo con una hora a las 00:00 y X-MICROSOFT-CDO-ALLDAYEVENT', function () {
    $e = icalOne(icalDoc(
        icalRomance(),
        icalEvent('UID:a', 'DTSTART;TZID=Romance Standard Time:20261105T000000', 'DTEND;TZID=Romance Standard Time:20261106T000000', 'X-MICROSOFT-CDO-ALLDAYEVENT:TRUE', 'SUMMARY:A'),
    ));

    expect($e->start)->toBe('2026-11-05')->and($e->end)->toBe('2026-11-06')->and($e->allDay)->toBeTrue();
});

it('X-MICROSOFT-CDO-ALLDAYEVENT:TRUE con una hora que no es medianoche no convierte nada', function () {
    $e = icalOne(icalDoc(icalEvent('UID:a', 'DTSTART:20261105T100000', 'X-MICROSOFT-CDO-ALLDAYEVENT:TRUE', 'SUMMARY:A')));

    expect($e->start)->toBe('2026-11-05 10:00:00')->and($e->allDay)->toBeNull();
});

it('un segundo intercalar (:60) se lee como :59', function () {
    expect(icalOne(icalDoc(icalEvent('UID:a', 'DTSTART:20261231T235960Z', 'SUMMARY:A')))->start->format('H:i:s'))->toBe('23:59:59');
});

it('acepta una hora sin segundos', function () {
    expect(icalOne(icalDoc(icalEvent('UID:a', 'DTSTART:20261016T0930', 'SUMMARY:A')))->start)->toBe('2026-10-16 09:30:00');
});

// ── Texto, parámetros y plegado ────────────────────────────────────────

it('desescapa \\n, \\,, \; y \\\\', function () {
    $e = icalOne(icalDoc(icalEvent('UID:a', 'DTSTART:20261016', 'SUMMARY:Cena\\, con \; y \\\\ barra', 'DESCRIPTION:Uno\\nDos\\NTres\\, cuatro')));

    expect($e->title)->toBe('Cena, con ; y \\ barra')->and($e->meta['description'])->toBe("Uno\nDos\nTres, cuatro");
});

it('los parámetros pueden ir entre comillas con :, ; y , dentro', function () {
    $e = icalOne(icalDoc(icalEvent(
        'UID:a', 'DTSTART:20261016', 'SUMMARY:A',
        'ATTENDEE;CN="Ruiz, Marta; Jr.: la jefa";ROLE=REQ-PARTICIPANT:mailto:marta@example.com',
        'ORGANIZER;CN=Sin comillas:MAILTO:org@example.com',
    )));

    expect($e->meta['attendees'])->toBe([['email' => 'marta@example.com', 'name' => 'Ruiz, Marta; Jr.: la jefa']])
        ->and($e->meta['organizer'])->toBe(['email' => 'org@example.com', 'name' => 'Sin comillas']);
});

it('los nombres de propiedad y de parámetro no distinguen mayúsculas', function () {
    $e = icalOne(icalDoc(icalEvent('uid:a', 'dtstart;tzid=Europe/Madrid:20261016T090000', 'dtend;value=date-time;tzid=Europe/Madrid:20261016T100000', 'summary:Minúsculas')));

    expect($e->title)->toBe('Minúsculas')->and($e->start->getTimezone()->getName())->toBe('Europe/Madrid');
});

it('despliega con CRLF, con LF solo, con CR solo y con tabulador', function () {
    $body = ["BEGIN:VEVENT", "UID:a", "DTSTART:20261016", "SUMMARY:Una línea muy larga que el exportador ha", " plegado en dos", "\tY otra vez con tabulador", "END:VEVENT"];

    foreach (["\r\n", "\n", "\r"] as $eol) {
        $ics = implode($eol, ['BEGIN:VCALENDAR', 'VERSION:2.0', ...$body, 'END:VCALENDAR']).$eol;

        expect(icalOne($ics)->title)->toBe('Una línea muy larga que el exportador haplegado en dosY otra vez con tabulador');
    }
});

it('un carácter UTF-8 partido entre dos líneas físicas se recompone al desplegar', function () {
    // ó = C3 B3; 🎉 = F0 9F 8E 89. El pliegue cae en medio de los dos.
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:a\r\nDTSTART:20261016\r\nSUMMARY:Reuni\xC3\r\n \xB3n \xF0\x9F\r\n \x8E\x89\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    expect(preg_match('//u', $ics))->not->toBe(1)
        ->and(icalOne($ics)->title)->toBe('Reunión 🎉');
});

it('ignora un BOM al principio y las líneas en blanco', function () {
    $ics = "\xEF\xBB\xBF".icalDoc("\n".icalEvent('UID:a', 'DTSTART:20261016', 'SUMMARY:Con BOM')."\n\n");

    expect(icalOne($ics)->title)->toBe('Con BOM');
});

it('un archivo en Windows-1252 se lee y avisa', function () {
    $ics = icalDoc(icalEvent('UID:a', 'DTSTART:20261016', "SUMMARY:Reuni\xF3n en Espa\xF1a"));

    [$events, $reader] = icalRead($ics);

    expect($events[0]->title)->toBe('Reunión en España')
        ->and($reader->warnings())->toBe(['El archivo no está en UTF-8; se interpretó como Windows-1252.']);
});

// ── Lo que se ignora ───────────────────────────────────────────────────

it('ignora VTODO, VJOURNAL, VFREEBUSY y VALARM sin fallar, y lo de un VALARM no se cuela en el evento', function () {
    [$events, $reader] = icalRead(icalDoc(
        "BEGIN:VTODO\nUID:t\nSUMMARY:Tarea\nDUE:20261020\nEND:VTODO",
        "BEGIN:VJOURNAL\nUID:j\nSUMMARY:Diario\nDTSTART:20261020\nEND:VJOURNAL",
        "BEGIN:VFREEBUSY\nUID:f\nDTSTART:20261020T000000Z\nFREEBUSY:20261020T100000Z/PT1H\nEND:VFREEBUSY",
        icalEvent('UID:a', 'DTSTART:20261016', 'SUMMARY:Evento', 'DESCRIPTION:Descripción real', "BEGIN:VALARM\nACTION:DISPLAY\nDESCRIPTION:Recordatorio\nSUMMARY:No soy el título\nTRIGGER:-PT15M\nEND:VALARM"),
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->title)->toBe('Evento')
        ->and($events[0]->meta['description'])->toBe('Descripción real')
        ->and($reader->errors())->toBe([])
        ->and($reader->warnings())->toBe([]);
});

it('un archivo sin eventos devuelve una lista vacía', function () {
    expect(icalRead(icalDoc())[0])->toBe([]);
});

it('lee varios VCALENDAR concatenados', function () {
    $ics = icalDoc(icalEvent('UID:a', 'DTSTART:20261016', 'SUMMARY:A')).icalDoc(icalEvent('UID:b', 'DTSTART:20261017', 'SUMMARY:B'));

    expect(array_map(fn ($e) => $e->id, icalRead($ics)[0]))->toBe(['a', 'b']);
});

// ── Ids ────────────────────────────────────────────────────────────────

it('el id es el UID; con uidDomain se le quita el @dominio', function () {
    $ics = icalDoc(
        icalEvent('UID:42@ej.test', 'DTSTART:20261016', 'SUMMARY:A'),
        icalEvent('UID:abc@google.com', 'DTSTART:20261016', 'SUMMARY:B'),
        icalEvent('UID:@ej.test', 'DTSTART:20261016', 'SUMMARY:C'),
    );

    expect(array_map(fn ($e) => $e->id, icalRead($ics)[0]))->toBe(['42@ej.test', 'abc@google.com', '@ej.test'])
        ->and(array_map(fn ($e) => $e->id, icalRead($ics, ['uidDomain' => 'ej.test'])[0]))->toBe(['42', 'abc@google.com', '@ej.test']);
});

it('una ocurrencia modificada tiene un id estable derivado de la serie y de su RECURRENCE-ID', function () {
    $ics = icalDoc(
        icalEvent('UID:s@ej.test', 'DTSTART;TZID=Europe/Madrid:20261012T090000', 'RRULE:FREQ=WEEKLY', 'SUMMARY:Serie'),
        icalEvent('UID:s@ej.test', 'RECURRENCE-ID;TZID=Europe/Madrid:20261019T090000', 'DTSTART;TZID=Europe/Madrid:20261019T100000', 'SUMMARY:Movida'),
    );

    [$series, $override] = icalRead($ics, ['uidDomain' => 'ej.test'])[0];
    [, $again] = icalRead($ics, ['uidDomain' => 'ej.test'])[0];

    expect($series->isRecurring())->toBeTrue()
        ->and($override->id)->toBe('s#20261019T090000')
        ->and($override->id)->toBe($again->id)
        ->and($override->recurrenceOf)->toBe('s')
        ->and($override->recurrenceId->format('c'))->toBe('2026-10-19T09:00:00+02:00')
        ->and($override->isOverride())->toBeTrue()
        ->and($override->rrule)->toBeNull();
});

it('un VEVENT sin UID recibe un id derivado de su contenido y un aviso; con strict es un error', function () {
    $ics = icalDoc(icalEvent('DTSTART:20261016', 'SUMMARY:Sin UID'));

    [$events, $reader] = icalRead($ics);
    [$again] = icalRead($ics);

    expect($events[0]->id)->toStartWith('ical-')->and($events[0]->id)->toBe($again[0]->id)
        ->and($reader->warnings()[0])->toContain('Línea 4: el VEVENT no tiene UID')
        ->and(fn () => icalRead($ics, ['strict' => true]))->toThrow(ICalException::class, 'Línea 4: el VEVENT no tiene UID');
});

it('un UID repetido avisa pero conserva los dos eventos', function () {
    [$events, $reader] = icalRead(icalDoc(
        icalEvent('UID:a', 'DTSTART:20261016', 'SUMMARY:Uno'),
        icalEvent('UID:a', 'DTSTART:20261017', 'SUMMARY:Dos'),
    ));

    expect($events)->toHaveCount(2)->and($reader->warnings()[0])->toContain('el UID «a» está repetido');
});

it('un VEVENT sin SUMMARY recibe el título de la opción untitled', function () {
    $ics = icalDoc(icalEvent('UID:a', 'DTSTART:20261016'));

    expect(icalOne($ics)->title)->toBe('(sin título)')->and(icalOne($ics, ['untitled' => 'Sin nombre'])->title)->toBe('Sin nombre');
});

// ── Propiedades ────────────────────────────────────────────────────────

it('URL, COLOR y X-KORE-RESOURCES van a sus campos', function () {
    $e = icalOne(icalDoc(icalEvent(
        'UID:a', 'DTSTART:20261016', 'SUMMARY:A',
        'URL:https://example.com/e?id=1,2', 'COLOR:chart-3', 'X-KORE-RESOURCES:ambar,7,sala\\, grande,',
    )));

    expect($e->url)->toBe('https://example.com/e?id=1,2')
        ->and($e->color)->toBe('chart-3')
        ->and($e->resourceIds)->toBe(['ambar', '7', 'sala, grande']);
});

it('lo que CalendarEvent no tiene va a meta, sin perderse ni romper la lectura', function () {
    $e = icalOne(icalDoc(icalEvent(
        'UID:a', 'DTSTART:20261016', 'SUMMARY:A',
        'DESCRIPTION:Texto', 'LOCATION:Sala A\\, planta 2', 'STATUS:confirmed', 'CATEGORIES:Trabajo,Equipo\\, A', 'CATEGORIES:Otra',
        'SEQUENCE:4', 'X-GOOGLE-CONFERENCE:https://meet.example/abc', 'X-OTRA:con\\nsalto',
        'CLASS:PRIVATE', 'PRIORITY:1', 'GEO:40.4;-3.7',
    )));

    expect($e->meta)->toBe([
        'description' => 'Texto',
        'location' => 'Sala A, planta 2',
        'status' => 'CONFIRMED',
        'categories' => ['Trabajo', 'Equipo, A', 'Otra'],
        'sequence' => 4,
        'x' => ['X-GOOGLE-CONFERENCE' => 'https://meet.example/abc', 'X-OTRA' => "con\nsalto"],
    ]);
});

it('una propiedad vacía (DESCRIPTION: sin texto) no deja una clave vacía en meta', function () {
    $e = icalOne(icalDoc(icalEvent('UID:a', 'DTSTART:20261016', 'SUMMARY:A', 'DESCRIPTION:', 'LOCATION:', 'URL:', 'COLOR:')));

    expect($e->meta)->toBe([])->and($e->url)->toBeNull()->and($e->color)->toBeNull();
});

it('el nombre del calendario sale de X-WR-CALNAME', function () {
    $reader = new ICalReader;
    $reader->read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nX-WR-CALNAME:Equipo\\, producto\r\nEND:VCALENDAR\r\n");

    expect($reader->calendarName())->toBe('Equipo, producto');
});

// ── Series ─────────────────────────────────────────────────────────────

it('RRULE se conserva tal cual y EXDATE (varias líneas, varias fechas por línea) se lee en la representación del inicio', function () {
    $e = icalOne(icalDoc(icalEvent(
        'UID:a', 'DTSTART;TZID=Europe/Madrid:20261012T090000', 'SUMMARY:A',
        'RRULE:FREQ=DAILY;COUNT=9',
        'EXDATE;TZID=Europe/Madrid:20261014T090000,20261015T090000',
        'EXDATE:20261016T070000Z',
    )));

    expect($e->rrule)->toBe('FREQ=DAILY;COUNT=9')
        ->and(array_map(fn ($d) => $d->format('c'), $e->exdates))->toBe(['2026-10-14T09:00:00+02:00', '2026-10-15T09:00:00+02:00', '2026-10-16T07:00:00+00:00']);
});

it('EXDATE de solo fecha en un día completo, y flotante en una serie flotante', function () {
    [$dia, $flot] = icalRead(icalDoc(
        icalEvent('UID:dia', 'DTSTART;VALUE=DATE:20261012', 'SUMMARY:A', 'RRULE:FREQ=DAILY;COUNT=9', 'EXDATE;VALUE=DATE:20261014,20261016'),
        icalEvent('UID:flot', 'DTSTART:20261012T090000', 'SUMMARY:B', 'RRULE:FREQ=DAILY;COUNT=9', 'EXDATE:20261014T090000'),
    ))[0];

    expect($dia->exdates)->toBe(['2026-10-14', '2026-10-16'])->and($flot->exdates)->toBe(['2026-10-14 09:00:00']);
});

it('una RRULE que php-rrule no entiende es un error de ese evento, con su línea', function () {
    [$events, $reader] = icalRead(icalDoc(
        icalEvent('UID:mala', 'DTSTART:20261016T090000', 'SUMMARY:A', 'RRULE:FREQ=CADA-DIA'),
        icalEvent('UID:buena', 'DTSTART:20261016T090000', 'SUMMARY:B'),
    ));

    expect($events)->toHaveCount(1)->and($events[0]->id)->toBe('buena')
        ->and($reader->errors())->toHaveCount(1)
        ->and($reader->errors()[0])->toStartWith('Línea 8: RRULE «FREQ=CADA-DIA» no válida');
});

it('RDATE y EXRULE avisan de que no se pueden representar', function () {
    [, $reader] = icalRead(icalDoc(icalEvent('UID:a', 'DTSTART:20261016T090000', 'SUMMARY:A', 'RRULE:FREQ=DAILY;COUNT=3', 'RDATE:20261101T090000', 'EXRULE:FREQ=DAILY;INTERVAL=2')));

    expect($reader->warnings())->toHaveCount(2)
        ->and($reader->warnings()[0])->toContain('RDATE no se puede representar')
        ->and($reader->warnings()[1])->toContain('EXRULE no se puede representar');
});

it('varios RRULE: se usa el primero y se avisa', function () {
    [$events, $reader] = icalRead(icalDoc(icalEvent('UID:a', 'DTSTART:20261016T090000', 'SUMMARY:A', 'RRULE:FREQ=DAILY;COUNT=3', 'RRULE:FREQ=WEEKLY')));

    expect($events[0]->rrule)->toBe('FREQ=DAILY;COUNT=3')->and($reader->warnings()[0])->toContain('varios RRULE');
});

it('EXDATE sin RRULE se ignora con un aviso', function () {
    [$events, $reader] = icalRead(icalDoc(icalEvent('UID:a', 'DTSTART:20261016T090000', 'SUMMARY:A', 'EXDATE:20261017T090000')));

    expect($events[0]->exdates)->toBe([])->and($reader->warnings()[0])->toContain('EXDATE sin RRULE');
});

it('una ocurrencia modificada con RRULE pierde la regla (no puede ser a la vez serie), con un aviso', function () {
    [$events, $reader] = icalRead(icalDoc(icalEvent('UID:a', 'DTSTART:20261016T090000', 'RECURRENCE-ID:20261016T080000', 'SUMMARY:A', 'RRULE:FREQ=DAILY;COUNT=3')));

    expect($events[0]->rrule)->toBeNull()->and($events[0]->isOverride())->toBeTrue()
        ->and($reader->warnings()[0])->toContain('una ocurrencia modificada no puede llevar RRULE');
});

it('RECURRENCE-ID;RANGE=THISANDFUTURE está obsoleto: se trata como una ocurrencia y se avisa', function () {
    [$events, $reader] = icalRead(icalDoc(icalEvent('UID:a', 'DTSTART:20261016T090000', 'RECURRENCE-ID;RANGE=THISANDFUTURE:20261016T080000', 'SUMMARY:A')));

    expect($events[0]->isOverride())->toBeTrue()->and($reader->warnings()[0])->toContain('RANGE=THISANDFUTURE está obsoleto');
});

it('el RECURRENCE-ID de una serie de día completo es una fecha', function () {
    $e = icalOne(icalDoc(icalEvent('UID:a', 'DTSTART;VALUE=DATE:20261016', 'RECURRENCE-ID;VALUE=DATE:20261014', 'SUMMARY:A')));

    expect($e->recurrenceId)->toBe('2026-10-14')->and($e->id)->toBe('a#20261014');
});

// ── Zonas de Windows / Outlook ─────────────────────────────────────────

it('un TZID de Windows se resuelve con el VTIMEZONE del archivo y se avisa, una sola vez', function () {
    [$events, $reader] = icalRead(icalDoc(
        icalRomance(),
        icalEvent('UID:a', 'DTSTART;TZID=Romance Standard Time:20261020T100000', 'DTEND;TZID=Romance Standard Time:20261020T113000', 'SUMMARY:A'),
        icalEvent('UID:b', 'DTSTART;TZID=Romance Standard Time:20261125T100000', 'SUMMARY:B'),
    ));

    expect($events[0]->start->getTimezone()->getName())->toBe('Europe/Madrid')
        ->and($events[0]->start->format('c'))->toBe('2026-10-20T10:00:00+02:00')
        ->and($events[1]->start->format('c'))->toBe('2026-11-25T10:00:00+01:00')
        ->and($reader->warnings())->toHaveCount(1)
        ->and($reader->warnings()[0])->toContain('el TZID «Romance Standard Time» no es una zona IANA')->toContain('«Europe/Madrid»');
});

it('la zona asociada sigue el cambio de hora: una serie de Outlook no se corre una hora en invierno', function () {
    $e = icalOne(icalDoc(
        icalRomance(),
        icalEvent('UID:s', 'DTSTART;TZID=Romance Standard Time:20261020T100000', 'DTEND;TZID=Romance Standard Time:20261020T110000', 'RRULE:FREQ=WEEKLY;COUNT=3;BYDAY=TU', 'SUMMARY:S'),
    ));

    $instances = icalInstances([$e], '2026-10-01', '2026-12-01');
    usort($instances, fn ($a, $b) => $a->range->start->ts <=> $b->range->start->ts);
    $starts = array_map(fn ($i) => $i->occurrenceStart->format('Y-m-d H:i P'), $instances);

    expect($starts)->toBe(['2026-10-20 10:00 +02:00', '2026-10-27 10:00 +01:00', '2026-11-03 10:00 +01:00']);
});

it('un TZID de Windows sin VTIMEZONE no se puede resolver: error de ese evento con su línea', function () {
    [$events, $reader] = icalRead(icalDoc(
        icalEvent('UID:a', 'DTSTART;TZID=Romance Standard Time:20261020T100000', 'SUMMARY:A'),
        icalEvent('UID:b', 'DTSTART:20261020T100000', 'SUMMARY:B'),
    ));

    expect($events)->toHaveCount(1)->and($events[0]->id)->toBe('b')
        ->and($reader->errors())->toBe(['Línea 6: el TZID «Romance Standard Time» no es una zona horaria conocida y el archivo no trae su VTIMEZONE, así que no se sabe a qué hora corresponde.']);
});

it('un VTIMEZONE sin cambios de hora da un desplazamiento fijo', function () {
    [$events, $reader] = icalRead(icalDoc(
        implode("\n", ['BEGIN:VTIMEZONE', 'TZID:Arabian Standard Time', 'BEGIN:STANDARD', 'DTSTART:16010101T000000', 'TZOFFSETFROM:+0400', 'TZOFFSETTO:+0400', 'END:STANDARD', 'END:VTIMEZONE']),
        icalEvent('UID:a', 'DTSTART;TZID=Arabian Standard Time:20261020T100000', 'SUMMARY:A'),
    ));

    expect($events[0]->start->format('c'))->toBe('2026-10-20T10:00:00+04:00')
        ->and($reader->warnings()[0])->toContain('no tiene cambios de hora')->toContain('+04:00');
});

it('un VTIMEZONE con cambios de hora que ninguna zona de PHP tiene da el desplazamiento de la fecha del evento, con un aviso claro', function () {
    $rara = implode("\n", [
        'BEGIN:VTIMEZONE', 'TZID:Zona Rara',
        'BEGIN:STANDARD', 'DTSTART:16010101T030000', 'TZOFFSETFROM:+0230', 'TZOFFSETTO:+0130', 'RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10', 'END:STANDARD',
        'BEGIN:DAYLIGHT', 'DTSTART:16010101T020000', 'TZOFFSETFROM:+0130', 'TZOFFSETTO:+0230', 'RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=3', 'END:DAYLIGHT',
        'END:VTIMEZONE',
    ]);

    [$events, $reader] = icalRead(icalDoc(
        $rara,
        icalEvent('UID:verano', 'DTSTART;TZID=Zona Rara:20260715T100000', 'SUMMARY:A'),
        icalEvent('UID:invierno', 'DTSTART;TZID=Zona Rara:20261215T100000', 'SUMMARY:B'),
    ));

    expect($events[0]->start->format('c'))->toBe('2026-07-15T10:00:00+02:30')
        ->and($events[1]->start->format('c'))->toBe('2026-12-15T10:00:00+01:30')
        ->and($reader->warnings())->toHaveCount(1)
        ->and($reader->warnings()[0])->toContain('ninguna coincide con su VTIMEZONE')->toContain('una serie no seguirá los cambios de hora');
});

it('un VTIMEZONE roto es un aviso, y solo falla el evento que lo usa', function () {
    [$events, $reader] = icalRead(icalDoc(
        "BEGIN:VTIMEZONE\nTZID:Rota\nBEGIN:STANDARD\nTZOFFSETFROM:+0100\nEND:STANDARD\nEND:VTIMEZONE",
        icalEvent('UID:a', 'DTSTART;TZID=Rota:20261020T100000', 'SUMMARY:A'),
        icalEvent('UID:b', 'DTSTART:20261020T100000', 'SUMMARY:B'),
    ));

    expect($events)->toHaveCount(1)
        ->and($reader->warnings()[0])->toContain('necesita DTSTART, TZOFFSETFROM y TZOFFSETTO')
        ->and($reader->errors()[0])->toContain('«Rota» no es una zona horaria conocida');
});

// ── Errores ────────────────────────────────────────────────────────────

it('un VEVENT sin DTSTART es un error con la línea de su BEGIN', function () {
    [$events, $reader] = icalRead(icalDoc(icalEvent('UID:a', 'SUMMARY:Sin inicio')));

    expect($events)->toBe([])->and($reader->errors())->toBe(['Línea 4: el VEVENT no tiene DTSTART.']);
});

it('una fecha mal escrita o imposible es un error con la línea de esa propiedad', function () {
    [, $reader] = icalRead(icalDoc(
        icalEvent('UID:a', 'SUMMARY:A', 'DTSTART:2026-10-16'),
        icalEvent('UID:b', 'SUMMARY:B', 'DTSTART:20261345T100000'),
        icalEvent('UID:c', 'SUMMARY:C', 'DTSTART:20261016T250000'),
        icalEvent('UID:d', 'SUMMARY:D', 'DTSTART:20261016T100000', 'DTEND:mañana'),
    ));

    expect($reader->errors())->toBe([
        'Línea 7: DTSTART «2026-10-16» no es una fecha iCalendar válida (se espera 20261016, 20261016T090000 o 20261016T090000Z).',
        'Línea 12: DTSTART «20261345T100000» no es una fecha que exista.',
        'Línea 17: DTSTART «20261016T250000» no es una fecha que exista.',
        'Línea 23: DTEND «mañana» no es una fecha iCalendar válida (se espera 20261016, 20261016T090000 o 20261016T090000Z).',
    ]);
});

it('el número de línea cuenta las líneas físicas, también las plegadas', function () {
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:a\r\nSUMMARY:Un título tan largo\r\n  que ocupa tres líneas\r\n  del archivo\r\nDTSTART:no-es-fecha\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    $reader = new ICalReader;
    $reader->read($ics);

    expect($reader->errors()[0])->toStartWith('Línea 8: DTSTART «no-es-fecha»');
});

it('una línea que no es NOMBRE:valor rompe el VEVENT que la contiene, con su línea; el resto se lee', function () {
    [$events, $reader] = icalRead(icalDoc(
        icalEvent('UID:a', 'DTSTART:20261016', 'esto no es una propiedad', 'SUMMARY:A'),
        icalEvent('UID:b', 'DTSTART:20261017', 'SUMMARY:B'),
    ));

    expect(array_map(fn ($e) => $e->id, $events))->toBe(['b'])
        ->and($reader->errors()[0])->toStartWith('Línea 7: no es una propiedad iCalendar válida (se espera NOMBRE:valor): «esto no es una propiedad»');
});

it('un parámetro con comillas sin cerrar es un error de línea', function () {
    $reader = new ICalReader;
    $reader->read(icalDoc(icalEvent('UID:a', 'DTSTART:20261016', 'SUMMARY:A', 'ATTENDEE;CN="sin cerrar:mailto:a@b.c')));

    expect($reader->errors()[0])->toStartWith('Línea 8: el parámetro CN abre comillas y no las cierra.');
});

it('un VEVENT sin END se salta; el siguiente se lee', function () {
    $ics = icalDoc("BEGIN:VEVENT\nUID:a\nDTSTART:20261016\nSUMMARY:Sin cerrar", icalEvent('UID:b', 'DTSTART:20261017', 'SUMMARY:B'));

    [$events, $reader] = icalRead($ics);

    expect(array_map(fn ($e) => $e->id, $events))->toBe(['b'])
        ->and($reader->errors())->toBe(['Línea 4: el VEVENT no se cierra (falta END:VEVENT).']);
});

it('un archivo truncado se lee hasta donde llega: el último VEVENT roto es un error y la falta de END:VCALENDAR un aviso', function () {
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:a\r\nDTSTART:20261016\r\nSUMMARY:A\r\nEND:VEVENT\r\nBEGIN:VEVENT\r\nUID:b\r\nDTSTART:20261017\r\nSUMM";

    [$events, $reader] = icalRead($ics);

    expect(array_map(fn ($e) => $e->id, $events))->toBe(['a'])
        ->and($reader->errors()[0])->toContain('Línea 8: el VEVENT no se cierra')
        ->and($reader->warnings()[0])->toContain('Línea 1: el VCALENDAR no se cierra')->toContain('truncado');
});

it('un END sin BEGIN se ignora con un aviso', function () {
    [$events, $reader] = icalRead(icalDoc("END:VALARM\n".icalEvent('UID:a', 'DTSTART:20261016', 'SUMMARY:A')));

    expect($events)->toHaveCount(1)->and($reader->warnings())->toBe(['Línea 4: END:VALARM sin su BEGIN; se ignora.']);
});

it('lo que no es un archivo iCalendar lanza, también sin strict', function (string $content) {
    expect(fn () => (new ICalReader)->read($content))->toThrow(ICalException::class, 'no es un archivo iCalendar');
})->with(['', 'hola', '{"json": true}', "BEGIN:VEVENT\r\nUID:a\r\nDTSTART:20261016\r\nEND:VEVENT\r\n", '<html><body>Not found</body></html>']);

it('una versión que no es 2.0 avisa', function () {
    $reader = new ICalReader;
    $reader->read("BEGIN:VCALENDAR\r\nVERSION:1.0\r\nEND:VCALENDAR\r\n");

    expect($reader->warnings()[0])->toContain('VERSION «1.0» no es 2.0');
});

// ── strict ─────────────────────────────────────────────────────────────

it('por defecto un VEVENT roto se salta y se informa; los demás se leen', function () {
    [$events, $reader] = icalRead(icalDoc(
        icalEvent('UID:a', 'DTSTART:20261016', 'SUMMARY:Bien'),
        icalEvent('UID:b', 'DTSTART:roto', 'SUMMARY:Mal'),
        icalEvent('UID:c', 'DTSTART:20261018', 'SUMMARY:Bien también'),
    ));

    expect(array_map(fn ($e) => $e->id, $events))->toBe(['a', 'c'])->and($reader->errors())->toHaveCount(1);
});

it('con strict lanza en el primer error, con su línea', function () {
    $ics = icalDoc(
        icalEvent('UID:a', 'DTSTART:20261016', 'SUMMARY:Bien'),
        icalEvent('UID:b', 'DTSTART:roto', 'SUMMARY:Mal'),
        icalEvent('UID:c', 'DTSTART:peor', 'SUMMARY:Peor'),
    );

    try {
        icalRead($ics, ['strict' => true]);
        $this->fail('Debía lanzar.');
    } catch (ICalException $e) {
        expect($e->icsLine)->toBe(11)->and($e->getMessage())->toStartWith('Línea 11: DTSTART «roto»');
    }
});

it('con strict un archivo truncado también lanza; los avisos solos no', function () {
    expect(fn () => icalRead("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:a\r\nDTSTART:20261016\r\nEND:VEVENT\r\n", ['strict' => true]))
        ->toThrow(ICalException::class, 'no se cierra');

    // Un RDATE avisa, no falla.
    expect(icalRead(icalDoc(icalEvent('UID:a', 'DTSTART:20261016T090000', 'SUMMARY:A', 'RRULE:FREQ=DAILY;COUNT=2', 'RDATE:20261101T090000')), ['strict' => true])[0])->toHaveCount(1);
});

it('cada lectura empieza de cero: errors() y warnings() no acumulan las de la anterior', function () {
    $reader = new ICalReader;
    $reader->read(icalDoc(icalEvent('UID:a', 'SUMMARY:Sin inicio')));
    expect($reader->errors())->toHaveCount(1);

    $reader->read(icalDoc(icalEvent('UID:a', 'DTSTART:20261016', 'SUMMARY:A')));
    expect($reader->errors())->toBe([])->and($reader->warnings())->toBe([]);
});

it('lanza con una opción desconocida', function () {
    expect(fn () => (new ICalReader)->read(icalDoc(), ['estricto' => true]))->toThrow(InvalidArgumentException::class, 'opción desconocida «estricto». Permitidas: strict, uidDomain, untitled');
});

it('X-KORE-COLOR gana a COLOR al leer', function () {
    $e = icalOne(icalDoc(icalEvent('UID:a', 'DTSTART:20261016', 'SUMMARY:A', 'X-KORE-COLOR:chart-3', 'COLOR:tomato')));

    expect($e->color)->toBe('chart-3');
});
