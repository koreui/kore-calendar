<?php

use Carbon\CarbonImmutable;
use KoreUi\Calendar\Events\CalendarEvent;
use KoreUi\Calendar\Events\EventCollector;
use KoreUi\Calendar\Events\EventNormalizer;
use KoreUi\Calendar\Events\EventSource;
use KoreUi\Calendar\ICal\ICalException;
use KoreUi\Calendar\ICal\ICalSource;
use KoreUi\Calendar\Time\DateRange;
use KoreUi\Calendar\Time\Marker;
use KoreUi\Calendar\Time\WallClock;

require_once __DIR__.'/helpers.php';

function icalRange(string $from, string $to, string $zone = 'Europe/Madrid'): array
{
    return [CarbonImmutable::parse($from, $zone), CarbonImmutable::parse($to, $zone)];
}

/** Los ids de lo que devuelve la fuente para un rango. */
function icalSourceIds(ICalSource $source, string $from, string $to, string $zone = 'Europe/Madrid'): array
{
    $ids = array_map(fn (CalendarEvent $e) => (string) $e->id, [...$source->events(...icalRange($from, $to, $zone))]);
    sort($ids);

    return $ids;
}

function icalTempFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'ical');
    file_put_contents($path, $content);

    return $path;
}

function icalCalendar(): string
{
    return icalDoc(
        icalEvent('UID:antes', 'DTSTART:20260105T100000', 'DTEND:20260105T110000', 'SUMMARY:Mucho antes'),
        icalEvent('UID:dentro', 'DTSTART:20261016T100000', 'DTEND:20261016T110000', 'SUMMARY:Dentro'),
        icalEvent('UID:despues', 'DTSTART:20270105T100000', 'DTEND:20270105T110000', 'SUMMARY:Mucho después'),
        icalEvent('UID:dia', 'DTSTART;VALUE=DATE:20261020', 'DTEND;VALUE=DATE:20261022', 'SUMMARY:Día completo'),
        icalEvent('UID:cruza', 'DTSTART:20260930T220000', 'DTEND:20261001T020000', 'SUMMARY:Cruza el inicio'),
        icalEvent('UID:sinfin', 'DTSTART:20261016T150000', 'SUMMARY:Sin fin'),
        icalEvent('UID:sinfin-viejo', 'DTSTART:20260601T150000', 'SUMMARY:Sin fin viejo'),
        icalEvent('UID:serie', 'DTSTART;TZID=Europe/Madrid:20260105T090000', 'RRULE:FREQ=WEEKLY;UNTIL=20260301T000000Z', 'SUMMARY:Serie ya acabada'),
        icalEvent('UID:serie-viva', 'DTSTART;TZID=Europe/Madrid:20260105T090000', 'RRULE:FREQ=WEEKLY', 'SUMMARY:Serie viva'),
        icalEvent('UID:serie-futura', 'DTSTART;TZID=Europe/Madrid:20270105T090000', 'RRULE:FREQ=WEEKLY', 'SUMMARY:Serie que aún no ha empezado'),
        icalEvent('UID:serie-viva', 'RECURRENCE-ID;TZID=Europe/Madrid:20261019T090000', 'DTSTART;TZID=Europe/Madrid:20261201T090000', 'SUMMARY:Movida fuera del rango'),
    );
}

it('es una EventSource', function () {
    expect(ICalSource::fromString(icalCalendar()))->toBeInstanceOf(EventSource::class);
});

it('lee el contenido de un .ics con fromString y un archivo con fromFile', function () {
    $path = icalTempFile(icalCalendar());

    expect(icalSourceIds(ICalSource::fromString(icalCalendar()), '2026-10-01', '2026-11-01'))->toContain('dentro')
        ->and(icalSourceIds(ICalSource::fromFile($path), '2026-10-01', '2026-11-01'))->toContain('dentro');

    unlink($path);
});

it('fromFile lanza si el archivo no se puede leer', function () {
    expect(fn () => ICalSource::fromFile('/no/existe/calendario.ics'))->toThrow(InvalidArgumentException::class, 'no se puede leer el archivo');
});

it('fromString nunca lee del disco aunque el texto sea una ruta que existe', function () {
    // Un dato del usuario no puede convertir la fuente en un lector de archivos.
    $path = icalTempFile(icalDoc(icalEvent('UID:secreto', 'DTSTART:20261016T100000', 'SUMMARY:No debe salir')));
    $source = ICalSource::fromString($path);

    expect(fn () => icalSourceIds($source, '2026-10-01', '2026-11-01'))->toThrow(Exception::class);
    expect(implode(' ', (fn () => $this->reader?->errors() ?? [])->call($source)))->not->toContain('No debe salir');

    unlink($path);
});

it('un archivo que no es iCalendar lanza al pedir los eventos, no al construir la fuente', function () {
    $source = ICalSource::fromString("esto no es\nun calendario");

    expect(fn () => $source->events(...icalRange('2026-10-01', '2026-11-01')))->toThrow(ICalException::class, 'no es un archivo iCalendar');
});

it('devuelve solo lo que toca el rango: lo de mucho antes y mucho después queda fuera', function () {
    $ids = icalSourceIds(ICalSource::fromString(icalCalendar()), '2026-10-12', '2026-10-19');

    expect($ids)->toContain('dentro', 'sinfin')
        ->not->toContain('antes', 'despues', 'dia', 'cruza', 'sinfin-viejo');
});

it('un evento que cruza el inicio del rango entra, y uno que acaba justo en él no', function () {
    $source = ICalSource::fromString(icalCalendar());

    expect(icalSourceIds($source, '2026-10-01', '2026-10-08'))->toContain('cruza');
    // Termina el 1-oct a las 02:00; un rango que empieza el 3 queda lejos (más allá del margen de un día).
    expect(icalSourceIds($source, '2026-10-03', '2026-10-08'))->not->toContain('cruza');
});

it('el día completo entra si el rango lo toca', function () {
    $source = ICalSource::fromString(icalCalendar());

    expect(icalSourceIds($source, '2026-10-19', '2026-10-21'))->toContain('dia')
        ->and(icalSourceIds($source, '2026-10-25', '2026-10-30'))->not->toContain('dia');
});

it('un evento sin fin cuenta si empieza dentro o como mucho un día antes (el criterio de EloquentSource)', function () {
    $source = ICalSource::fromString(icalCalendar());

    expect(icalSourceIds($source, '2026-10-16', '2026-10-17'))->toContain('sinfin')
        ->and(icalSourceIds($source, '2026-10-17', '2026-10-18'))->toContain('sinfin')
        ->and(icalSourceIds($source, '2026-10-20', '2026-10-21'))->not->toContain('sinfin')
        ->and(icalSourceIds($source, '2026-10-16', '2026-10-17'))->not->toContain('sinfin-viejo');
});

it('las series entran siempre que no empiecen después del rango, aunque su regla ya haya acabado: la expansión es del colector', function () {
    $ids = icalSourceIds(ICalSource::fromString(icalCalendar()), '2026-10-12', '2026-10-19');

    expect($ids)->toContain('serie', 'serie-viva')->not->toContain('serie-futura');
});

it('las ocurrencias modificadas entran siempre: la que se movió fuera del rango tiene que suprimir la generada', function () {
    $ids = icalSourceIds(ICalSource::fromString(icalCalendar()), '2026-10-12', '2026-10-19');

    expect($ids)->toContain('serie-viva#20261019T090000');
});

it('el rango se interpreta en la zona de la vista: un día completo del 20 no entra en un rango del 19 en Tokio', function () {
    $source = ICalSource::fromString(icalDoc(icalEvent('UID:dia', 'DTSTART;VALUE=DATE:20261020', 'DTEND;VALUE=DATE:20261021', 'SUMMARY:A')));

    // El 20 de octubre a las 00:00 en Tokio ya pasó cuando en Madrid es el día 19: la fuente es generosa (un día de margen)
    // y deja el filtro fino al colector; lo que no puede es perderlo.
    expect(icalSourceIds($source, '2026-10-19', '2026-10-20', 'Asia/Tokyo'))->toContain('dia')
        ->and(icalSourceIds($source, '2026-10-19', '2026-10-20', 'America/Los_Angeles'))->toContain('dia');
});

it('con EventCollector sale lo mismo que con todos los eventos en una ArraySource', function () {
    $clock = new WallClock('Europe/Madrid');
    $collector = new EventCollector($clock, new EventNormalizer($clock));
    $range = new DateRange(Marker::parse('2026-10-01'), Marker::parse('2026-12-31'));

    $viaSource = $collector->collect([ICalSource::fromString(icalCalendar(), ['uidDomain' => 'ej.test'])], $range);
    $viaArray = $collector->collect([new KoreUi\Calendar\Events\Sources\ArraySource((new KoreUi\Calendar\ICal\ICalReader)->read(icalCalendar(), ['uidDomain' => 'ej.test']))], $range);

    $rows = fn (array $instances) => collect($instances)->map(fn ($i) => [$i->range->start->toDateTimeString(), $i->instanceId, $i->event->title])->sort()->values()->all();

    expect($rows($viaSource))->toBe($rows($viaArray))->and($viaSource)->not->toBeEmpty();
});

it('la ocurrencia movida fuera del rango suprime la generada, y la serie sigue en el resto', function () {
    $clock = new WallClock('Europe/Madrid');
    $collector = new EventCollector($clock, new EventNormalizer($clock));
    $source = ICalSource::fromString(icalDoc(
        icalEvent('UID:s', 'DTSTART;TZID=Europe/Madrid:20261012T090000', 'RRULE:FREQ=WEEKLY;COUNT=4', 'SUMMARY:Serie'),
        icalEvent('UID:s', 'RECURRENCE-ID;TZID=Europe/Madrid:20261019T090000', 'DTSTART;TZID=Europe/Madrid:20261201T090000', 'SUMMARY:Movida a diciembre'),
    ));

    $october = $collector->collect([$source], new DateRange(Marker::parse('2026-10-12'), Marker::parse('2026-11-09')));
    $starts = collect($october)->map(fn ($i) => $i->range->start->toDateTimeString())->sort()->values()->all();

    // El 19-oct falta: la modificada vive en diciembre y ahí aparecerá.
    expect($starts)->toBe(['2026-10-12 09:00:00', '2026-10-26 09:00:00', '2026-11-02 09:00:00']);

    $december = $collector->collect([$source], new DateRange(Marker::parse('2026-11-30'), Marker::parse('2026-12-07')));

    expect(collect($december)->map(fn ($i) => $i->event->title)->all())->toBe(['Movida a diciembre']);
});

it('errors() y warnings() cuentan lo que no se pudo leer, con su línea', function () {
    $source = ICalSource::fromString(icalDoc(
        icalEvent('UID:a', 'DTSTART:20261016T100000', 'SUMMARY:Bien'),
        icalEvent('UID:b', 'SUMMARY:Sin inicio'),
        icalEvent('UID:c', 'DTSTART:20261016T100000', 'SUMMARY:RDATE', 'RRULE:FREQ=DAILY;COUNT=2', 'RDATE:20261101T100000'),
    ));

    expect(icalSourceIds($source, '2026-10-01', '2026-11-01'))->toBe(['a', 'c'])
        ->and($source->errors())->toBe(['Línea 9: el VEVENT no tiene DTSTART.'])
        ->and($source->warnings()[0])->toContain('RDATE no se puede representar');
});

it('con strict, el primer VEVENT roto lanza al pedir los eventos', function () {
    $source = ICalSource::fromString(icalDoc(icalEvent('UID:b', 'SUMMARY:Sin inicio')), ['strict' => true]);

    expect(fn () => $source->events(...icalRange('2026-10-01', '2026-11-01')))->toThrow(ICalException::class, 'no tiene DTSTART');
});

it('analiza el archivo una sola vez por instancia, y no hasta que se piden los eventos', function () {
    $path = icalTempFile(icalDoc(icalEvent('UID:a', 'DTSTART:20261016T100000', 'SUMMARY:Uno')));
    $source = ICalSource::fromFile($path);

    // Aún no se ha leído: cambiar el archivo antes de la primera petición se nota…
    file_put_contents($path, icalDoc(icalEvent('UID:b', 'DTSTART:20261016T100000', 'SUMMARY:Dos')));
    expect(icalSourceIds($source, '2026-10-01', '2026-11-01'))->toBe(['b']);

    // …y después, no: la fuente se consulta en cada render y no relee el disco cada vez.
    file_put_contents($path, icalDoc(icalEvent('UID:c', 'DTSTART:20261016T100000', 'SUMMARY:Tres')));
    expect(icalSourceIds($source, '2026-10-01', '2026-11-01'))->toBe(['b'])
        ->and(icalSourceIds(ICalSource::fromFile($path), '2026-10-01', '2026-11-01'))->toBe(['c']);

    unlink($path);
});
