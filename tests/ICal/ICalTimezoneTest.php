<?php

use KoreUi\Calendar\Events\CalendarEvent;

require_once __DIR__.'/helpers.php';

/*
| El VTIMEZONE que escribe ICalWriter, generado desde la base de datos de zonas de PHP. Un
| VTIMEZONE mal puesto no da error en ningún sitio: el evento aparece una hora corrido en
| Outlook. Por eso se comprueba de dos maneras: las transiciones concretas, y que leído como
| lo leería un cliente dé el mismo desplazamiento que PHP en cada día del rango.
*/

/**
 * @param  list<string>  $block
 * @return list<array<string, string>> Los STANDARD/DAYLIGHT de un VTIMEZONE.
 */
function icalComponents(array $block): array
{
    $components = [];
    $current = null;

    foreach ($block as $line) {
        if ($line === 'BEGIN:STANDARD' || $line === 'BEGIN:DAYLIGHT') {
            $current = ['type' => substr($line, 6)];
        } elseif ($line === 'END:STANDARD' || $line === 'END:DAYLIGHT') {
            $components[] = $current;
            $current = null;
        } elseif ($current !== null) {
            [$name, $value] = explode(':', $line, 2);
            $current[$name] = $value;
        }
    }

    return $components;
}

function icalTz(string $zone, string $from, ?string $to = null, ?string $rrule = null): array
{
    $tz = new DateTimeZone($zone);
    $events = [CalendarEvent::make(id: 'a', title: 'A', start: new DateTimeImmutable($from, $tz))];

    if ($to !== null || $rrule !== null) {
        $events[] = CalendarEvent::make(id: 'b', title: 'B', start: new DateTimeImmutable($to ?? $from, $tz), rrule: $rrule);
    }

    $blocks = icalBlocks(icalWrite($events), 'VTIMEZONE');

    return $blocks[0];
}

it('Europe/Madrid 2026: pasa a CEST el 29 de marzo y vuelve a CET el 25 de octubre, a las 02:00 y 03:00 de pared', function () {
    $components = icalComponents(icalTz('Europe/Madrid', '2026-10-16 09:00'));

    expect($components)->toBe([
        ['type' => 'DAYLIGHT', 'TZNAME' => 'CEST', 'TZOFFSETFROM' => '+0100', 'TZOFFSETTO' => '+0200', 'DTSTART' => '20260329T020000'],
        ['type' => 'STANDARD', 'TZNAME' => 'CET', 'TZOFFSETFROM' => '+0200', 'TZOFFSETTO' => '+0100', 'DTSTART' => '20261025T030000'],
    ]);
});

it('America/New_York 2026: EDT el 8 de marzo y EST el 1 de noviembre, a las 02:00 de pared', function () {
    $components = icalComponents(icalTz('America/New_York', '2026-10-16 09:00'));

    expect($components)->toBe([
        ['type' => 'DAYLIGHT', 'TZNAME' => 'EDT', 'TZOFFSETFROM' => '-0500', 'TZOFFSETTO' => '-0400', 'DTSTART' => '20260308T020000'],
        ['type' => 'STANDARD', 'TZNAME' => 'EST', 'TZOFFSETFROM' => '-0400', 'TZOFFSETTO' => '-0500', 'DTSTART' => '20261101T020000'],
    ]);
});

it('en el hemisferio sur el año empieza en horario de verano: Australia/Sydney vuelve a estándar en abril', function () {
    $components = icalComponents(icalTz('Australia/Sydney', '2026-10-16 09:00'));

    expect($components)->toBe([
        ['type' => 'STANDARD', 'TZNAME' => 'AEST', 'TZOFFSETFROM' => '+1100', 'TZOFFSETTO' => '+1000', 'DTSTART' => '20260405T030000'],
        ['type' => 'DAYLIGHT', 'TZNAME' => 'AEDT', 'TZOFFSETFROM' => '+1000', 'TZOFFSETTO' => '+1100', 'DTSTART' => '20261004T020000'],
    ]);
});

it('cubre los años de los eventos, ni uno más ni uno menos', function () {
    $years = fn (array $block) => array_values(array_unique(array_map(fn ($c) => substr($c['DTSTART'], 0, 4), icalComponents($block))));

    expect($years(icalTz('Europe/Madrid', '2026-10-16 09:00', '2028-02-01 09:00')))->toBe(['2026', '2027', '2028'])
        ->and(icalComponents(icalTz('Europe/Madrid', '2026-10-16 09:00', '2028-02-01 09:00')))->toHaveCount(6);
});

it('una serie con UNTIL cubre hasta el año de su UNTIL', function () {
    $years = array_unique(array_map(fn ($c) => substr($c['DTSTART'], 0, 4), icalComponents(icalTz('Europe/Madrid', '2026-10-12 09:00', null, 'FREQ=MONTHLY;UNTIL=20290115T080000Z'))));

    expect(array_values($years))->toBe(['2026', '2027', '2028', '2029']);
});

it('una serie con COUNT cubre hasta su última ocurrencia', function () {
    $years = array_unique(array_map(fn ($c) => substr($c['DTSTART'], 0, 4), icalComponents(icalTz('Europe/Madrid', '2026-10-12 09:00', null, 'FREQ=YEARLY;COUNT=3'))));

    expect(array_values($years))->toBe(['2026', '2027', '2028']);
});

it('una serie sin final cubre diez años más, para que un cliente que use el VTIMEZONE la vea bien', function () {
    $years = array_unique(array_map(fn ($c) => substr($c['DTSTART'], 0, 4), icalComponents(icalTz('Europe/Madrid', '2026-10-12 09:00', null, 'FREQ=WEEKLY'))));

    expect(array_values($years))->toBe(array_map('strval', range(2026, 2036)));
});

it('un UNTIL absurdo (9999) no escribe miles de años de cambios de hora', function () {
    expect(count(icalComponents(icalTz('Europe/Madrid', '2026-10-12 09:00', null, 'FREQ=WEEKLY;UNTIL=99991231T000000Z'))))->toBeLessThanOrEqual(2 * 31);
});

it('una zona sin cambios de hora lleva un único componente con el mismo desplazamiento de origen y destino', function () {
    expect(icalComponents(icalTz('Asia/Dubai', '2026-10-16 09:00')))->toBe([
        ['type' => 'STANDARD', 'TZNAME' => '+04', 'TZOFFSETFROM' => '+0400', 'TZOFFSETTO' => '+0400', 'DTSTART' => '19700101T000000'],
    ]);
});

it('una zona se declara una sola vez aunque la usen varios eventos', function () {
    $tz = new DateTimeZone('Europe/Madrid');
    $ics = icalWrite([
        CalendarEvent::make(id: 'a', title: 'A', start: new DateTimeImmutable('2026-10-16 09:00', $tz)),
        CalendarEvent::make(id: 'b', title: 'B', start: new DateTimeImmutable('2026-11-16 09:00', $tz), end: new DateTimeImmutable('2026-11-16 10:00', $tz)),
    ]);

    expect(icalBlocks($ics, 'VTIMEZONE'))->toHaveCount(1);
});

it('el VTIMEZONE va antes del primer VEVENT, y cada TZID usado tiene el suyo', function () {
    $ny = new DateTimeZone('America/New_York');
    $mad = new DateTimeZone('Europe/Madrid');
    $ics = icalWrite([
        CalendarEvent::make(id: 'a', title: 'A', start: new DateTimeImmutable('2026-10-16 09:00', $mad), end: new DateTimeImmutable('2026-10-16 10:00', $ny)),
        CalendarEvent::make(id: 'b', title: 'B', start: '2026-10-12T01:00:00+02:00', rrule: 'FREQ=DAILY;COUNT=2'),
    ]);

    $lines = icalLines($ics);
    $firstEvent = array_search('BEGIN:VEVENT', $lines, true);
    $lastTimezone = array_search('END:VTIMEZONE', array_reverse($lines, true), true);

    preg_match_all('/TZID=([^:;]+):/', $ics, $used);
    $declared = array_map(fn ($b) => substr($b[1], 5), icalBlocks($ics, 'VTIMEZONE'));

    expect($lastTimezone)->toBeLessThan($firstEvent)
        ->and(array_values(array_unique($used[1])))->each->toBeIn($declared)
        ->and($declared)->toBe(['America/New_York', 'Europe/Madrid', 'UTC+0200']);
});

it('cada componente es válido: DTSTART, TZOFFSETFROM y TZOFFSETTO con el formato de RFC 5545', function () {
    $block = icalTz('Europe/Madrid', '2026-10-16 09:00', '2028-02-01 09:00');

    expect($block[0])->toBe('BEGIN:VTIMEZONE')->and($block[1])->toBe('TZID:Europe/Madrid')->and(end($block))->toBe('END:VTIMEZONE');

    foreach (icalComponents($block) as $c) {
        expect($c['DTSTART'])->toMatch('/^\d{8}T\d{6}$/')
            ->and($c['TZOFFSETFROM'])->toMatch('/^[+-]\d{4}(\d{2})?$/')
            ->and($c['TZOFFSETTO'])->toMatch('/^[+-]\d{4}(\d{2})?$/')
            ->and($c['TZOFFSETFROM'])->not->toBe($c['TZOFFSETTO']);
    }

    // Cada TZOFFSETFROM es el TZOFFSETTO del componente anterior: la cadena no tiene huecos.
    $components = icalComponents($block);
    foreach ($components as $i => $c) {
        if ($i > 0) {
            expect($c['TZOFFSETFROM'])->toBe($components[$i - 1]['TZOFFSETTO']);
        }
    }
});

// Leído como lo haría un cliente, el VTIMEZONE da el mismo desplazamiento que PHP, día a día.
it('el VTIMEZONE escrito da el mismo desplazamiento que la base de datos de zonas en cada día del rango', function (string $zone) {
    $block = icalTz($zone, '2026-02-01 09:00', '2027-12-01 09:00');
    $definition = icalDefinition($block);
    $php = new DateTimeZone($zone);
    $checked = 0;

    for ($ts = gmmktime(0, 0, 0, 1, 1, 2026); $ts < gmmktime(0, 0, 0, 1, 1, 2028); $ts += 3600 * 5) {
        expect($definition->offsetAt($ts))->toBe($php->getOffset(new DateTimeImmutable('@'.$ts)), gmdate('c', $ts));
        $checked++;
    }

    expect($checked)->toBeGreaterThan(3000);
})->with(['Europe/Madrid', 'America/New_York', 'Australia/Sydney', 'Asia/Dubai', 'Asia/Kolkata', 'America/Sao_Paulo', 'Europe/London']);

it('el desplazamiento es el correcto justo antes y justo después de cada cambio', function () {
    $definition = icalDefinition(icalTz('Europe/Madrid', '2026-10-16 09:00'));

    // 29-mar-2026 01:00 UTC: de +1 a +2. 25-oct-2026 01:00 UTC: de +2 a +1.
    $spring = gmmktime(1, 0, 0, 3, 29, 2026);
    $autumn = gmmktime(1, 0, 0, 10, 25, 2026);

    expect([$definition->offsetAt($spring - 1), $definition->offsetAt($spring), $definition->offsetAt($autumn - 1), $definition->offsetAt($autumn)])
        ->toBe([3600, 7200, 7200, 3600]);
});
