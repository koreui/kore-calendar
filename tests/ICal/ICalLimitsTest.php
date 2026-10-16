<?php

use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventCollector;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Events\Sources\ArraySource;
use KoreUi\Calendar\ICal\ICalException;
use KoreUi\Calendar\ICal\ICalReader;
use KoreUi\Calendar\ICal\ICalSource;
use KoreUi\Calendar\ICal\Text;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;

require_once __DIR__.'/helpers.php';

/*
| Un `.ics` lo escribe un tercero: lo que traiga no puede gastar más tiempo o memoria que unos
| topes (auditoría de seguridad: H2, H4, H6, H7, H10 y H14 del lado iCalendar). Cada prueba
| reproduce el ataque con un archivo hostil y comprueba que termina rápido y con poca memoria;
| el comportamiento legítimo lo cubren las demás suites de ICal.
*/

/** Un archivo con `$body` dentro de un VCALENDAR, ya con CRLF. */
function ilDoc(string $body): string
{
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n{$body}END:VCALENDAR\r\n";
}

/** @return array{0: list<CalendarEvent>, 1: ICalReader} */
function ilRead(string $ics, array $options = []): array
{
    $reader = new ICalReader;

    return [$reader->read($ics, $options), $reader];
}

function ilOne(string $ics, array $options = []): CalendarEvent
{
    [$events] = ilRead($ics, $options);

    return $events[0];
}

// ── H2: VTIMEZONE hostil ────────────────────────────────────────────────

function ilZone(string $rule, string $tzid = 'Zona Hostil'): string
{
    return "BEGIN:VTIMEZONE\r\nTZID:{$tzid}\r\nBEGIN:STANDARD\r\nDTSTART:16010101T000000\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200\r\nRRULE:{$rule}\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n"
        ."BEGIN:VEVENT\r\nUID:z1\r\nDTSTART;TZID={$tzid}:20261016T090000\r\nDTEND;TZID={$tzid}:20261016T100000\r\nSUMMARY:x\r\nEND:VEVENT\r\n"
        ."BEGIN:VEVENT\r\nUID:ok\r\nDTSTART:20261016T090000Z\r\nSUMMARY:ok\r\nEND:VEVENT\r\n";
}

it('un VTIMEZONE con FREQ=SECONDLY desde 1601 se ignora con un aviso, sin agotar la memoria', function (string $rule) {
    $reader = new ICalReader;

    [$events, $seconds, $memory] = icalMeasure(fn () => $reader->read(ilDoc(ilZone($rule))));

    // Se resuelve como un TZID desconocido: el evento que lo usa es un error, el otro se lee.
    expect(array_map(fn ($e) => $e->id, $events))->toBe(['ok'])
        ->and($reader->warnings()[0])->toContain('no es FREQ=YEARLY')->toContain('«Zona Hostil»')
        ->and($reader->errors()[0])->toContain('no es una zona horaria conocida')
        ->and($seconds)->toBeLessThan(1.0)
        ->and($memory)->toBeLessThan(16);
})->with(['FREQ=SECONDLY', 'FREQ=MINUTELY;INTERVAL=2', 'FREQ=HOURLY', 'FREQ=DAILY', 'FREQ=MONTHLY;BYDAY=-1SU', 'FREQ=YEARLY;FREQ=SECONDLY', 'freq=secondly']);

it('un VTIMEZONE FREQ=YEARLY con BYxxx que multiplican las ocurrencias está acotado por los topes', function () {
    $rule = 'FREQ=YEARLY;BYMONTH=1,2,3,4,5,6,7,8,9,10,11,12;BYMONTHDAY='.implode(',', range(1, 28)).';BYHOUR='.implode(',', range(0, 23)).';BYMINUTE='.implode(',', range(0, 59)).';BYSECOND='.implode(',', range(0, 59));
    $reader = new ICalReader;

    [$events, $seconds, $memory] = icalMeasure(fn () => $reader->read(ilDoc(ilZone($rule))));

    expect($events)->not->toBeEmpty()
        ->and($seconds)->toBeLessThan(3.0)
        ->and($memory)->toBeLessThan(40);
});

it('un VTIMEZONE con diez mil RDATE se ignora', function () {
    $rdates = implode(',', array_map(fn ($i) => gmdate('Ymd\THis', 86400 * (3000 + $i)), range(0, 10000)));
    $ics = ilDoc("BEGIN:VTIMEZONE\r\nTZID:Muchas\r\nBEGIN:STANDARD\r\nDTSTART:19700101T000000\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200\r\nRDATE:{$rdates}\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n"
        ."BEGIN:VEVENT\r\nUID:a\r\nDTSTART;TZID=Muchas:20261016T090000\r\nSUMMARY:x\r\nEND:VEVENT\r\n");
    $reader = new ICalReader;
    $reader->read($ics);

    expect($reader->warnings()[0])->toContain('más de 10000 fechas RDATE')->and($reader->errors())->toHaveCount(1);
});

it('un VTIMEZONE legítimo (YEARLY de Outlook, desde 1601) sigue resolviéndose', function () {
    $reader = new ICalReader;
    $events = $reader->read(ilDoc(ilZone('FREQ=YEARLY;INTERVAL=1;BYDAY=-1SU;BYMONTH=10', 'Romance Standard Time')));

    expect(array_map(fn ($e) => $e->id, $events))->toBe(['z1', 'ok'])->and($reader->errors())->toBe([]);
});

// ── H6: topes del lector ────────────────────────────────────────────────

it('35 000 BEGIN/END desparejados no son cuadráticos', function () {
    $ics = ilDoc(str_repeat("BEGIN:X-A\r\n", 35000).str_repeat("END:X-B\r\n", 35000));
    $reader = new ICalReader;

    [, $seconds, $memory] = icalMeasure(fn () => $reader->read($ics));

    // Era 12 s. Y a más de 16 niveles se deja de leer, que además evita desbordar la pila al liberarlos.
    expect($seconds)->toBeLessThan(1.0)->and($memory)->toBeLessThan(10)
        ->and($reader->errors()[0])->toContain('anidados a más de 16 niveles');
});

it('el emparejado de BEGIN/END cuenta por nombre: un END cuyo BEGIN no está abierto se ignora, y lo abierto entre medias se cierra roto', function () {
    $reader = new ICalReader;
    $events = $reader->read(ilDoc("END:VALARM\r\nBEGIN:VEVENT\r\nUID:a\r\nDTSTART:20261016\r\nBEGIN:VALARM\r\nACTION:DISPLAY\r\nEND:VEVENT\r\n"
        ."BEGIN:VEVENT\r\nUID:b\r\nDTSTART:20261017\r\nEND:VEVENT\r\n"));

    // El primer VEVENT cierra con un VALARM sin cerrar dentro: sigue siendo un evento leíble (el roto es el VALARM).
    expect(array_map(fn ($e) => $e->id, $events))->toBe(['a', 'b'])
        ->and($reader->warnings()[0])->toBe('Línea 3: END:VALARM sin su BEGIN; se ignora.');
});

it('300 000 líneas malas dentro de un VEVENT no cuestan 800 MB: son datos, no excepciones con traza', function () {
    $ics = ilDoc("BEGIN:VEVENT\r\nUID:a\r\nDTSTART:20261016T090000Z\r\n".str_repeat("sin dos puntos\r\n", 300000)."END:VEVENT\r\n");
    $reader = new ICalReader;

    [$events, $seconds, $memory] = icalMeasure(fn () => $reader->read($ics));

    expect($events)->toBe([])
        ->and($reader->errors())->toHaveCount(1)
        ->and($reader->errors()[0])->toStartWith('Línea 6: no es una propiedad iCalendar válida')
        ->and($memory)->toBeLessThan(40)
        ->and($seconds)->toBeLessThan(3.0);
});

it('300 000 líneas malas fuera de un VEVENT se cuentan, y solo se guardan maxErrors avisos', function () {
    $ics = ilDoc(str_repeat("sin dos puntos\r\n", 300000));
    $reader = new ICalReader;

    [, $seconds, $memory] = icalMeasure(fn () => $reader->read($ics));

    expect($reader->warnings())->toHaveCount(100)
        ->and($reader->warningCount())->toBe(300000)
        ->and($memory)->toBeLessThan(10)
        ->and($seconds)->toBeLessThan(3.0);
});

it('maxErrors acota lo que se guarda; errorCount() y warningCount() dicen cuántos hubo', function () {
    $events = array_map(fn ($i) => icalEvent("UID:e{$i}", 'SUMMARY:sin inicio'), range(1, 250));
    $reader = new ICalReader;
    $reader->read(icalDoc(...$events), ['maxErrors' => 10]);

    expect($reader->errors())->toHaveCount(10)->and($reader->errorCount())->toBe(250)
        ->and($reader->errors()[9])->toStartWith('Línea ');

    $reader->read(icalDoc(...$events), ['maxErrors' => 0]);
    expect($reader->errors())->toBe([])->and($reader->errorCount())->toBe(250);

    // Por defecto, 100.
    $reader->read(icalDoc(...$events));
    expect($reader->errors())->toHaveCount(100)->and($reader->errorCount())->toBe(250);
});

it('los errores y avisos se truncan a 200 caracteres', function () {
    $tzid = str_repeat('Z', 150);
    $rule = 'FREQ=NUNCA;'.str_repeat('X', 5000);
    $reader = new ICalReader;
    $reader->read(icalDoc(
        icalEvent('UID:a', "DTSTART;TZID={$tzid}:20261016T090000", 'SUMMARY:a'),
        icalEvent('UID:b', 'DTSTART:20261016T090000Z', "RRULE:{$rule}", 'SUMMARY:b'),
    ));

    expect($reader->errors())->toHaveCount(2)
        ->and(mb_strlen($reader->errors()[0]))->toBeLessThanOrEqual(200)
        ->and(mb_strlen($reader->errors()[1]))->toBeLessThanOrEqual(200)
        ->and($reader->errors()[1])->toEndWith('…');
});

it('maxBytes rechaza un archivo más grande, por defecto de 5 MB', function () {
    $big = ilDoc(str_repeat('X-RELLENO:'.str_repeat('a', 990)."\r\n", 5300));

    expect(strlen($big))->toBeGreaterThan(5 * 1024 * 1024);
    expect(fn () => (new ICalReader)->read($big))->toThrow(ICalException::class, 'pesa 5.1 MB y el máximo que se lee es 5 MB')
        // Se puede subir el tope...
        ->and(count((new ICalReader)->read($big, ['maxBytes' => 6 * 1024 * 1024])))->toBe(0)
        // ...o bajarlo.
        ->and(fn () => (new ICalReader)->read(icalDoc(icalEvent('UID:a', 'DTSTART:20261016', 'SUMMARY:a')), ['maxBytes' => 50]))->toThrow(ICalException::class, 'máximo');
});

it('maxBytes se lee del config si la opción no lo dice', function () {
    config(['kore-calendar.ical.max_bytes' => 100]);

    expect(fn () => (new ICalReader)->read(icalDoc(icalEvent('UID:a', 'DTSTART:20261016', 'SUMMARY:a'))))->toThrow(ICalException::class, 'máximo');
    expect(count((new ICalReader)->read(icalDoc(icalEvent('UID:a', 'DTSTART:20261016', 'SUMMARY:a')), ['maxBytes' => 100000])))->toBe(1);
});

it('ICalSource::fromFile no carga en memoria un archivo más grande que maxBytes', function () {
    $path = tempnam(sys_get_temp_dir(), 'ical');
    file_put_contents($path, ilDoc(str_repeat('X-RELLENO:'.str_repeat('a', 990)."\r\n", 3000)));

    [$result, , $memory] = icalMeasure(function () use ($path) {
        try {
            iterator_to_array(ICalSource::fromFile($path, ['maxBytes' => 100000])->events(new Carbon\CarbonImmutable('2026-10-01'), new Carbon\CarbonImmutable('2026-11-01')));
        } catch (ICalException $e) {
            return $e->getMessage();
        }

        return null;
    });

    unlink($path);

    // Leyó 100 001 bytes, no los 3 MB.
    expect($result)->toContain('máximo')->and($memory)->toBeLessThan(2);
});

it('maxEvents: a partir del evento siguiente se deja de leer y queda un error (con strict, una excepción)', function () {
    $events = array_map(fn ($i) => icalEvent("UID:e{$i}", 'DTSTART:20261016', 'SUMMARY:a'), range(1, 5));
    $reader = new ICalReader;
    $read = $reader->read(icalDoc(...$events), ['maxEvents' => 3]);

    expect(array_map(fn ($e) => $e->id, $read))->toBe(['e1', 'e2', 'e3'])
        ->and($reader->errors())->toHaveCount(1)
        ->and($reader->errors()[0])->toContain('más de 3 VEVENT')->toContain('maxEvents')->toStartWith('Línea 19:')
        // No es un archivo truncado: sin el aviso de «falta END:VCALENDAR».
        ->and($reader->warnings())->toBe([]);

    expect(fn () => (new ICalReader)->read(icalDoc(...$events), ['maxEvents' => 3, 'strict' => true]))->toThrow(ICalException::class, 'más de 3 VEVENT');
});

it('maxEvents por defecto son 10 000: un archivo con muchos más se corta rápido', function () {
    $ics = ilDoc(str_repeat("BEGIN:VEVENT\r\nUID:e\r\nDTSTART:20261016\r\nEND:VEVENT\r\n", 20000));
    $reader = new ICalReader;

    [$events, $seconds, $memory] = icalMeasure(fn () => $reader->read($ics));

    expect($events)->toHaveCount(10000)->and($reader->errors()[0])->toContain('más de 10000 VEVENT')
        ->and($seconds)->toBeLessThan(3.0)->and($memory)->toBeLessThan(60);
});

it('los topes son enteros positivos y los desconocidos se rechazan por nombre', function () {
    expect(fn () => (new ICalReader)->read(icalDoc(), ['maxBytes' => 0]))->toThrow(InvalidArgumentException::class, '`maxBytes` es un entero de al menos 1')
        ->and(fn () => (new ICalReader)->read(icalDoc(), ['maxEvents' => '10']))->toThrow(InvalidArgumentException::class, '`maxEvents`')
        ->and(fn () => (new ICalReader)->read(icalDoc(), ['maxErrors' => -1]))->toThrow(InvalidArgumentException::class, '`maxErrors` es un entero de al menos 0')
        ->and(fn () => (new ICalReader)->read(icalDoc(), ['maximo' => 1]))->toThrow(InvalidArgumentException::class, 'Permitidas: strict, uidDomain, untitled, maxBytes, maxEvents, maxErrors, idPrefix');
});

it('los topes por defecto están en el config', function () {
    expect(config('kore-calendar.ical'))->toBe(['max_bytes' => 5242880, 'max_events' => 10000, 'max_errors' => 100]);
});

it('un archivo de cinco millones de saltos de línea no se convierte en cinco millones de arrays', function () {
    $ics = "BEGIN:VCALENDAR\r\n".str_repeat("\n", 5 * 1024 * 1024 - 100)."END:VCALENDAR\r\n";
    $reader = new ICalReader;

    [$events, $seconds, $memory] = icalMeasure(fn () => $reader->read($ics));

    expect($events)->toBe([])->and($memory)->toBeLessThan(10)->and($seconds)->toBeLessThan(3.0);
});

it('demasiados componentes o propiedades pequeñas se cortan por el tamaño permitido', function () {
    $ics = ilDoc("BEGIN:VEVENT\r\nUID:a\r\nDTSTART:20261016T090000Z\r\n".str_repeat("X-A:1\r\n", intdiv(4 * 1024 * 1024, 6))."END:VEVENT\r\n");
    $reader = new ICalReader;

    [, $seconds, $memory] = icalMeasure(fn () => $reader->read($ics, ['maxBytes' => 5 * 1024 * 1024]));

    expect($reader->errors()[0])->toContain('demasiadas líneas')
        ->and($memory)->toBeLessThan(80)
        ->and($seconds)->toBeLessThan(3.0);
});

it('un EXDATE con 300 000 fechas se lee hasta 5 000 y avisa', function () {
    $dates = implode(',', array_map(fn ($i) => gmdate('Ymd\THis\Z', 1000000000 + $i * 3600), range(0, 300000)));
    $reader = new ICalReader;

    [$events, , $memory] = icalMeasure(fn () => $reader->read(ilDoc("BEGIN:VEVENT\r\nUID:a\r\nDTSTART:20261016T090000Z\r\nRRULE:FREQ=DAILY\r\nEXDATE:{$dates}\r\nEND:VEVENT\r\n")));

    expect($events[0]->exdates)->toHaveCount(5000)
        ->and($reader->warnings()[0])->toContain('más de 5000 EXDATE')
        ->and($memory)->toBeLessThan(40);
});

it('mil un ATTENDEE se leen mil', function () {
    $lines = implode("\r\n", array_map(fn ($i) => "ATTENDEE:mailto:a{$i}@x.test", range(1, 1500)));
    $reader = new ICalReader;
    $events = $reader->read(ilDoc("BEGIN:VEVENT\r\nUID:a\r\nDTSTART:20261016T090000Z\r\n{$lines}\r\nEND:VEVENT\r\n"));

    expect($events[0]->meta['attendees'])->toHaveCount(1000)->and($reader->warnings()[0])->toContain('más de 1000 ATTENDEE');
});

// Text::lines() es el desplegado de siempre, de una en una.
it('Text::lines y Text::unfold dan lo mismo que el desplegado con preg_split de antes', function (string $ics) {
    $reference = function (string $ics): array {
        $logical = [];

        foreach (preg_split('/\r\n|\n|\r/', $ics) as $index => $physical) {
            if ($logical !== [] && $physical !== '' && ($physical[0] === ' ' || $physical[0] === "\t")) {
                $logical[array_key_last($logical)][1] .= substr($physical, 1);

                continue;
            }

            $logical[] = [$index + 1, $physical];
        }

        return $logical;
    };

    expect(Text::unfold($ics))->toBe($reference($ics))
        ->and(iterator_to_array(Text::lines($ics), false))->toBe($reference($ics));
})->with([
    '', "\n", "\r\n", "a", "a\r\nb\r\n", "a\nb\rc\r\nd", "a\r\n b\r\n\tc\r\nd", " a\r\nb", "\r\n b", "a\r\n\r\n b", "a\r\r\nb", "a\n\n\nb\n", "UID:1\r\nSUMMARY:Un título tan largo\r\n  que ocupa tres líneas\r\n  del archivo\r\nDTSTART:no-es-fecha\r\n", "\r\n\r\n",
]);

// ── H10: TZID hostil ────────────────────────────────────────────────────

it('un TZID que PHP rechaza no aborta la lectura: es un error de ese evento', function (string $tzid) {
    $reader = new ICalReader;
    $events = $reader->read(icalDoc(
        icalEvent('UID:mal', "DTSTART;TZID=\"{$tzid}\":20261016T090000", 'SUMMARY:mal'),
        icalEvent('UID:bien', 'DTSTART:20261016T090000Z', 'SUMMARY:bien'),
    ));

    expect(array_map(fn ($e) => $e->id, $events))->toBe(['bien'])
        ->and($reader->errors())->toHaveCount(1)
        ->and($reader->errors()[0])->toContain('no es una zona horaria conocida');
})->with(['UTC+99:99', 'GMT-25:00', 'UTC+14:30', 'GMT-14:01', '+9959', "Europe/Madrid\0x", 'Hostile/../../etc', 'UTC+0060']);

it('un TZID con un desplazamiento dentro de ±14:00 sigue valiendo', function (string $tzid, string $expected) {
    $e = ilOne(icalDoc(icalEvent('UID:a', "DTSTART;TZID=\"{$tzid}\":20261016T090000", 'SUMMARY:a')));

    expect($e->start->format('P'))->toBe($expected);
})->with([['UTC+14:00', '+14:00'], ['GMT-12:00', '-12:00'], ['UTC+0530', '+05:30'], ['+0100', '+01:00'], ['GMT-01:00', '-01:00']]);

it('un TZID de 100 000 caracteres es un error de ese evento, no una entrada en las cachés', function () {
    $reader = new ICalReader;
    $events = $reader->read(icalDoc(
        icalEvent('UID:mal', 'DTSTART;TZID='.str_repeat('A', 100000).':20261016T090000', 'SUMMARY:mal'),
        icalEvent('UID:bien', 'DTSTART:20261016T090000Z', 'SUMMARY:bien'),
    ));

    expect($events)->toHaveCount(1)->and($reader->errors())->toHaveCount(1)->and(mb_strlen($reader->errors()[0]))->toBeLessThanOrEqual(200);
});

// ── H4: URL ─────────────────────────────────────────────────────────────

it('una URL que no es relativa ni http, https, mailto o tel se descarta con un aviso', function (string $url) {
    $reader = new ICalReader;
    $e = $reader->read(icalDoc(icalEvent('UID:a', 'DTSTART:20261016', 'SUMMARY:a', "URL:{$url}")))[0];

    expect($e->url)->toBeNull()
        ->and($reader->warnings())->toHaveCount(1)
        ->and($reader->warnings()[0])->toStartWith('Línea 8: la URL «')->toContain('se descarta');
})->with([
    'javascript:alert(1)', 'JavaScript:alert(1)', "java\tscript:alert(1)", "  javascript:alert(1)", 'vbscript:msgbox(1)', 'data:text/html;base64,PHNjcmlwdD4=',
    'file:///etc/passwd', 'ftp://x.test/a', 'blob:https://x.test/uuid', 'foo bar:baz', "https://x.test/\x01", 'x:y',
]);

it('una URL http, https, mailto, tel o relativa se conserva', function (string $url) {
    $reader = new ICalReader;
    $e = $reader->read(icalDoc(icalEvent('UID:a', 'DTSTART:20261016', 'SUMMARY:a', "URL:{$url}")))[0];

    expect($e->url)->toBe($url)->and($reader->warnings())->toBe([]);
})->with([
    'https://x.test/a?b=c#d', 'http://x.test', 'HTTPS://X.TEST/A', 'mailto:a@x.test', 'tel:+34600000000', '/reuniones/7', 'reuniones/7', './a:b', '?q=1', '#frag', '//x.test/a',
]);

// ── H7: idPrefix ────────────────────────────────────────────────────────

it('idPrefix prefija el id, el recurrenceOf y la id de las excepciones; también un id derivado', function () {
    $ics = icalDoc(
        icalEvent('UID:serie', 'DTSTART:20261012T090000', 'RRULE:FREQ=DAILY;COUNT=3', 'SUMMARY:Serie'),
        icalEvent('UID:serie', 'RECURRENCE-ID:20261013T090000', 'DTSTART:20261013T110000', 'SUMMARY:Movida'),
        icalEvent('DTSTART:20261014T090000', 'SUMMARY:Sin UID'),
    );
    [$events] = ilRead($ics, ['idPrefix' => 'ics:']);

    expect($events[0]->id)->toBe('ics:serie')
        ->and($events[1]->id)->toStartWith('ics:serie#')
        ->and($events[1]->recurrenceOf)->toBe('ics:serie')
        ->and($events[2]->id)->toStartWith('ics:ical-');

    // Sin la opción, como siempre.
    expect(ilRead($ics)[0][0]->id)->toBe('serie');
});

it('con idPrefix, el UID de un archivo ajeno no choca con el id de un evento propio al mezclar fuentes', function () {
    $ics = icalDoc(icalEvent('UID:1', 'DTSTART:20261016T090000', 'DTEND:20261016T100000', 'SUMMARY:Del archivo'));
    $own = ['id' => '1', 'title' => 'Propio', 'start' => '2026-10-17 09:00:00', 'end' => '2026-10-17 10:00:00'];
    $clock = new WallClock('UTC');
    $collect = fn (ICalSource $source) => (new EventCollector($clock, new EventNormalizer($clock)))
        ->collect([new ArraySource([$own]), $source], new DateRange(Marker::parse('2026-10-01'), Marker::parse('2026-11-01')));

    $without = array_map(fn ($i) => $i->event->id, $collect(ICalSource::fromString($ics)));
    $with = array_map(fn ($i) => (string) $i->event->id, $collect(ICalSource::fromString($ics, ['idPrefix' => 'ics:'])));
    sort($with);

    // Sin prefijo los dos eventos comparten id `1`; con él son distintos.
    expect($without)->toBe(['1', '1'])->and($with)->toBe(['1', 'ics:1']);
});

it('idPrefix acepta lo mismo que uidDomain: se quita el dominio y luego se antepone el prefijo', function () {
    $e = ilOne(icalDoc(icalEvent('UID:7@miempresa.es', 'DTSTART:20261016', 'SUMMARY:a')), ['uidDomain' => 'miempresa.es', 'idPrefix' => 'ics:']);

    expect($e->id)->toBe('ics:7');
});

// ── H14: nombres X- del escritor ────────────────────────────────────────

it('el escritor no admite un nombre X- con un salto de línea al final (la $ de PCRE acepta un \n final)', function () {
    $e = CalendarEvent::make(
        id: 'a', title: 'a', start: '2026-10-16 09:00:00', end: '2026-10-16 10:00:00',
        meta: ['x' => ["X-FOO\n" => 'v', "X-BAR\r\nATTENDEE:mailto:evil@x.test" => 'v', "X-BAZ\r" => 'v', 'X-OK' => 'bien']],
    );
    $ics = icalWrite([$e]);

    expect(icalVevent($ics, 'a@ej.test'))->toContain('X-OK:bien')
        ->and(implode("\n", icalVevent($ics, 'a@ej.test')))->not->toContain('X-FOO')->not->toContain('X-BAR')->not->toContain('X-BAZ')->not->toContain('ATTENDEE');
});

// ── H1 en el escritor: EXDATE de un día sobre una regla de alta frecuencia ──

it('un EXDATE de solo fecha sobre FREQ=MINUTELY desde 2020 no recorre tres millones de ocurrencias', function () {
    $series = CalendarEvent::make(
        id: 's', title: 'S', start: '2020-01-01 00:00:00', end: '2020-01-01 00:01:00',
        rrule: 'FREQ=MINUTELY', exdates: ['2026-10-12'],
    );

    [$ics, $seconds, $memory] = icalMeasure(fn () => icalWrite([$series]));

    $exdates = array_values(array_filter(icalVevent($ics, 's@ej.test'), fn ($l) => str_starts_with($l, 'EXDATE')));

    // Un día tiene 1 440 minutos; el tope de ocurrencias por día (max_occurrences) deja las 1 000 primeras.
    expect($exdates)->toHaveCount(1)
        ->and(count(explode(',', $exdates[0])))->toBe(1000)
        ->and($seconds)->toBeLessThan(1.5)
        ->and($memory)->toBeLessThan(30);
});

it('un EXDATE de solo fecha sobre una regla que no se puede recorrer hasta ese día se queda con el día', function () {
    config(['kore-calendar.recurrence.max_iterations' => 1000]);
    $series = CalendarEvent::make(
        id: 's', title: 'S', start: '2020-01-01 00:00:00', end: '2020-01-01 00:01:00',
        rrule: 'FREQ=MINUTELY;COUNT=99999999', exdates: ['2026-10-12'],
    );

    $ics = icalWrite([$series]);

    expect(implode("\n", icalVevent($ics, 's@ej.test')))->toContain('EXDATE;VALUE=DATE:20261012');
});
